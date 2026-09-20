<?php

/**
 * Thrown when the eWAY Settlement Search API reports that the report for a
 * given date range has not been built yet ("data will be available in
 * 60 mins approx"). This is an expected, transient condition: the caller
 * skips that day and the next scheduled run re-issues the identical query.
 */
class CRM_eWAYRecurring_SettlementNotReadyException extends \RuntimeException {

}
