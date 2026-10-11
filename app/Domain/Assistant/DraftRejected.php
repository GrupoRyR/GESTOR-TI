<?php

namespace App\Domain\Assistant;

use RuntimeException;

/**
 * A `/aprender` text that cannot become a draft (empty, too long or with
 * a password). The message is Spanish and can be shown as is.
 */
final class DraftRejected extends RuntimeException {}
