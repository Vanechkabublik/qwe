<?php
class Env {
    private static $data = [];
    
    public static function load($file = '.env') {
        if (!file_exists($file)) return;
        
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) continue;
            
            list($key, $value) = explode('=', $line, 2);
            self::$data[trim($key)] = trim($value);
        }
    }
    
    public static function get($key, $default = null) {
        return self::$data[$key] ?? $default;
    }
}

Env::load(__DIR__ . '/.env');

class S3Uploader {
    private $s3;
    private $bucket;
    
    public function __construct() {
        require_once 'aws/aws-autoloader.php';
        
        $this->s3 = new Aws\S3\S3Client([
            'version' => 'latest',
            'region'  => Env::get('S3_REGION'),
            'endpoint' => Env::get('S3_ENDPOINT'),
            'credentials' => [
                'key'    => Env::get('S3_ACCESS_KEY'),
                'secret' => Env::get('S3_SECRET_KEY')
            ],
            'use_path_style_endpoint' => true,
        ]);
        
        $this->bucket = Env::get('S3_BUCKET');
    }
    
    public function upload($file) {
        // Валидация файла
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('File upload error: ' . $file['error']);
        }
        
        if ($file['size'] > 10 * 1024 * 1024) {
            throw new Exception('File too large. Max 10MB allowed');
        }
        
        $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg', 'image/webp'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        if (!in_array($mime, $allowedTypes)) {
            throw new Exception('Invalid file type. Allowed: JPEG, PNG, WebP');
        }
        
        // Генерируем уникальное имя
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $key = 'images/' . uniqid() . '_' . time() . '.' . $extension;
        
        try {
            $result = $this->s3->putObject([
                'Bucket' => $this->bucket,
                'Key'    => $key,
                'Body'   => fopen($file['tmp_name'], 'rb'),
                'ACL'    => 'public-read',
                'ContentType' => $mime
            ]);
            
            return [
                'key' => $key,
                'url' => $result['ObjectURL'],
                'filename' => $file['name']
            ];
            
        } catch (AwsException $e) {
            throw new Exception('S3 upload failed: ' . $e->getMessage());
        }
    }
    public function uploadFromUrl($url, $filename) {
    try {
        // Скачиваем файл по URL
        $fileContent = file_get_contents($url);
        if ($fileContent === false) {
            throw new Exception('Failed to download file from URL');
        }
        
        // Определяем Content-Type
        $contentType = $this->getContentTypeFromUrl($url);
        
        $result = $this->s3->putObject([
            'Bucket' => $this->bucket,
            'Key'    => $filename,
            'Body'   => $fileContent,
            'ACL'    => 'public-read',
            'ContentType' => $contentType
        ]);
        
        return [
            'key' => $filename,
            'url' => $result['ObjectURL']
        ];
        
    } catch (AwsException $e) {
        throw new Exception('S3 upload from URL failed: ' . $e->getMessage());
    }
}

private function getContentTypeFromUrl($url) {
    $extension = strtolower(pathinfo($url, PATHINFO_EXTENSION));
    
    $contentTypes = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'mp4' => 'video/mp4',
        'mov' => 'video/quicktime',
        'avi' => 'video/x-msvideo'
    ];
    
    return $contentTypes[$extension] ?? 'application/octet-stream';
}
}

class ReplicateService {
    private $api_key;
    
    public function __construct() {
        $this->api_key = Env::get('REPLICATE_API_KEY');
        if (!$this->api_key) {
            throw new Exception('Replicate API key not found');
        }
    }
    
    public function createPrediction($model, $input) {
        $ch = curl_init();
        
        $data = [
            'version' => $this->getModelVersion($model),
            'input' => $input
        ];
        
        curl_setopt_array($ch, [
            CURLOPT_URL => "https://api.replicate.com/v1/predictions",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => [
                'Authorization: Token ' . $this->api_key,
                'Content-Type: application/json',
            ],
        ]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code !== 201) {
            throw new Exception('Replicate API error: ' . $response);
        }
        
        return json_decode($response, true);
    }
    
    public function getPrediction($id) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => "https://api.replicate.com/v1/predictions/" . $id,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Token ' . $this->api_key,
                'Content-Type: application/json',
            ],
        ]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code !== 200) {
            throw new Exception('Prediction not found');
        }
        
        return json_decode($response, true);
    }
    
    public function formatQuality($quality) {
        $qualityMap = [
            '360' => '360p',
            '540' => '540p', 
            '720' => '720p',
            '1080' => '1080p'
        ];
        
        return $qualityMap[$quality] ?? '720p';
    }
    
    private function getModelVersion($model) {
        $versions = [
            'ddcolor' => 'ca494ba129e44e45f661d6ece83c4c98a9a7c774309beca01429b58fce8aa695',
            'restore' => 'flux-kontext-apps/restore-image', 
            'pixverse' => 'pixverse/pixverse-v5'
        ];
        
        return $versions[$model] ?? $model;
    }
}

function jsonResponse($success, $data = null, $error = null, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    
    $response = [
        'success' => $success,
        'timestamp' => time()
    ];
    
    if ($success) {
        $response['data'] = $data;
    } else {
        $response['error'] = [
            'code' => 'API_ERROR',
            'message' => $error
        ];
    }
    
    echo json_encode($response);
    exit;
}
?>