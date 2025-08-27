<?php
require __DIR__ . "/security_bootstrap.php";
header("Content-Type: text/plain; charset=utf-8");
printf("APP_IS_PROD=%s\n", ($GLOBALS["APP_IS_PROD"]??false) ? "true":"false");
printf("APP_IS_HTTPS=%s\n", ($GLOBALS["APP_IS_HTTPS"]??false) ? "true":"false");
printf("Host: %s\n", $_SERVER["HTTP_HOST"] ?? "unknown");
printf("Scheme seen by server: %s\n", (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") ? "https":"http");
printf("Request URI: %s\n", $_SERVER["REQUEST_URI"] ?? "/");
