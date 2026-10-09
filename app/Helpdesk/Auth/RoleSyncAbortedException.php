<?php namespace Helpdesk\Auth;

/**
 * A role sync run would have deactivated more users than the configured
 * share allows, so it stopped before writing anything.
 */
class RoleSyncAbortedException extends \RuntimeException {}
