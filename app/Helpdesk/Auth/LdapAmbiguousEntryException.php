<?php namespace Helpdesk\Auth;

/**
 * The directory search matched more than one entry for a username, so the
 * user cannot be identified. It says nothing about whether the user still
 * exists, which is why it is not an outage and not an absence.
 */
class LdapAmbiguousEntryException extends \RuntimeException {}
