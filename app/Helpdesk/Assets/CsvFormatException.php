<?php namespace Helpdesk\Assets;

use UnexpectedValueException;

/**
 * The file cannot be imported at all: it is missing, unreadable, or its
 * header lacks a required column.
 */
class CsvFormatException extends UnexpectedValueException {}
