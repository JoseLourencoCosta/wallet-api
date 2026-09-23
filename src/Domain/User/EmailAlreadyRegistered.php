<?php

declare(strict_types=1);

namespace App\Domain\User;

use RuntimeException;

final class EmailAlreadyRegistered extends RuntimeException {}
