<?php $classes = array('in_use' => 'label-primary', 'in_stock' => 'label-success', 'repair' => 'label-warning', 'retired' => 'label-default'); ?>
<span class="label {{ isset($classes[$status]) ? $classes[$status] : 'label-default' }}">{{{ str_replace('_', ' ', $status) }}}</span>
