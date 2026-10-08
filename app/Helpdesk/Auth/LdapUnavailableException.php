<?php namespace Helpdesk\Auth;

use RuntimeException;

/**
 * The directory could not be asked: no connection, the service account
 * bind failed or a search failed. Distinct from "no such user", so an
 * outage is never mistaken for an account that has been removed.
 */
class LdapUnavailableException extends RuntimeException {}
