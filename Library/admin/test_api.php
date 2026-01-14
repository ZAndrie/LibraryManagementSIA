<?php
require_once '../includes/book_api.php';

$api = new BookAPI();
$result = $api->searchBook('0590353403', '', '');

echo '<pre>';
print_r($result);
echo '</pre>';
?>