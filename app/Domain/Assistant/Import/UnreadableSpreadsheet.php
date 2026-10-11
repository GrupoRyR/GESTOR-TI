<?php

namespace App\Domain\Assistant\Import;

use RuntimeException;

/**
 * The file is not a readable .xlsx/.csv (corrupt, renamed, password-protected).
 */
final class UnreadableSpreadsheet extends RuntimeException {}
