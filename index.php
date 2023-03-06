<?php

require './Mind.php';

$conf = array(
    'db'=>[
        'drive'     =>  'mysql', // mysql, sqlite, sqlsrv
        'host'      =>  'localhost', // sqlsrv için: www.example.com\\MSSQLSERVER,'.(int)1433
        'dbname'    =>  'mydb', // mydb, app/migration/mydb.sqlite
        'username'  =>  'root',
        'password'  =>  '',
        'charset'   =>  'utf8mb4'
    ]
);
$Mind = new Mind($conf);

$Mind->route('/', 'app/views/welcome');
