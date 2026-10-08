<?php
$statusClasses = array(
	'new'      => 'label-info',
	'open'     => 'label-primary',
	'pending'  => 'label-warning',
	'resolved' => 'label-success',
	'closed'   => 'label-default',
);
?>
<span class="label {{ isset($statusClasses[$status]) ? $statusClasses[$status] : 'label-default' }}">{{{ $status }}}</span>
