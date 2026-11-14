<?php
$host = 'localhost';
$port = '3306';
$db = 'mamosta farman';
$user = 'root';
$pass = '12345678';

try{
    $dsn ="mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4";
    $pdo = new PDO($dsn,$user,$pass,[
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO:: ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch(PDOException $e){
    die("هەڵە هەیە لە داتابەیسەکەت" . $e->getMessage());
}