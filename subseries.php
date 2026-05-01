<?php
require_once 'db.php';
requireLogin();
header('Location: series.php?view=subseries');
exit;
