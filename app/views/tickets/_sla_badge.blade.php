<?php
$slaClasses = array(
	'ok'       => 'label-success',
	'warning'  => 'label-warning',
	'breached' => 'label-danger',
);
$slaState = isset($slaClasses[$state]) ? $state : 'ok';
?>
<span class="label {{ $slaClasses[$slaState] }} sla-{{ $slaState }}">SLA {{{ $slaState }}}</span>
