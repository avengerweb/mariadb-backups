<?php
require 'vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();
$dotenv->required(['BACKUP_PATH']);

$backup = new \App\Backup($_ENV['BACKUP_PATH']);
$backup->checkAndBackup();
