<?php

require_once __DIR__."/MiniXapi.php";	

$miniXapi=new MiniXapi();
$miniXapi->setBasicAuth("hello:world");
$miniXapi->serve();