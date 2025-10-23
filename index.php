<?php

require_once 'aws/aws-autoloader.php';

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

$config = [
    'version' => 'latest',
    'region'  => 'ru-1',
    'endpoint' => 'https://s3.twcstorage.ru',
    'credentials' => [
        'key'    => 'S2HVWMLBFNZGKNH32U62',
        'secret' => 'tBv0TqbpAQKT56Hg8LBLYuXTbnYLihA43ALaHBbZ'
    ],
    'use_path_style_endpoint' => true
];

$bucketName = '528d926c-denisovivan';

$s3 = new S3Client($config);

if(isset($_FILES['file'])) {
    $file = $_FILES['file'];
    $key = uniqid() . '_' . $file['name'];
    
    try {
        $result = $s3->putObject([
            'Bucket' => $bucketName,
            'Key'    => $key,
            'Body'   => fopen($file['tmp_name'], 'rb'),
            'ACL'    => 'public-read'
        ]);
        
        echo "Файл загружен: " . $result['ObjectURL'];
        
    } catch (AwsException $e) {
        echo "Ошибка: " . $e->getMessage();
    }
}
?>

<form method="post" enctype="multipart/form-data">
    <input type="file" name="file">
    <input type="submit" value="Загрузить в S3">
</form>