<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/** Signals a deliberate rollback in an isolated PostgreSQL test worker. */
final class StudentElectionTestRollback extends RuntimeException {}
