<?php

require './Mind.php';
use Mind\Mind;

$Mind = new Mind();

$Mind->route('/', 'app/views/welcome');
