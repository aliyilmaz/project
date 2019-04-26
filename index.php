<?php

require './Mind.php';
$Mind = new Mind();

$Mind->route('/', 'app/views/welcome');
