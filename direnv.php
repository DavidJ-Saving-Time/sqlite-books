<?php
$output = shell_exec('direnv exec ./python python3 ./python/book_recommend.py');
echo "<pre>$output</pre>";
?>
