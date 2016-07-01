<?php

$day=13;
$month=2;
$year=2015;

echo date("Y/m/d", mktime(0, 0, 0, $month, $day, $year));
echo '<p>';
echo date("Y/m/d", mktime(0, 0, 0, $month+1, $day-1, $year));

?>
