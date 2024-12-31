<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2024 Poweradmin Development Team
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

namespace PoweradminInstall\Validators;

use InvalidArgumentException;
use PDO;
use PDOException;
use PoweradminInstall\LocaleHandler;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Constraints as Assert;

class ConfiguringDatabaseValidator extends AbstractStepValidator
{
    const MYSQL_MAX_USERNAME_LENGTH = 32;
    const PGSQL_MAX_USERNAME_LENGTH = 63;
    private const MIN_PORT = 1;
    private const MAX_PORT = 65535;

    public function validate(): array
    {
        $constraints = new Assert\Collection([
            'submit' => [
                new Assert\NotBlank(),
            ],
            'step' => [
                new Assert\NotBlank(),
            ],
            'language' => [
                new Assert\NotBlank(),
                new Assert\Choice(['choices' => LocaleHandler::getAvailableLanguages()]),
            ],
            'db_type' => [
                new Assert\NotBlank(),
                new Assert\Choice(['choices' => ['mysql', 'pgsql', 'sqlite']]),
            ],
            'db_user' => [
                new Assert\Optional(),
                new Assert\Callback([$this, 'validateDbUser']),
            ],
            'db_pass' => [
                new Assert\Optional(),
                new Assert\Callback([$this, 'validateDbPass']),
            ],
            'db_host' => [
                new Assert\Optional(),
                new Assert\Callback([$this, 'validateDbHost']),
            ],
            'db_port' => [
                new Assert\NotBlank(),
                new Assert\Type('string'),
                new Assert\Callback([$this, 'validateDbPort']),
            ],
            'db_name' => [
                new Assert\NotBlank(),
                new Assert\Callback([$this, 'validateDbName']),
            ],
            'db_charset' => [
                new Assert\Optional(),
                new Assert\Callback([$this, 'validateDbCharset']),
            ],
            'db_collation' => [
                new Assert\Optional(),
                new Assert\Callback([$this, 'validateDbCollation']),
            ],
            'pa_pass' => [
                new Assert\NotBlank(),
                new Assert\Length([
                    'min' => 6,
                    'minMessage' => 'Poweradmin administrator password must be at least 6 characters long'
                ]),
                new Assert\Regex([
                    'pattern' => '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).+$/',
                    'message' => 'Poweradmin administrator password must contain at least one uppercase letter, one lowercase letter, and one number',
                ]),
            ],
        ]);

        $input = $this->request->request->all();
        $violations = $this->validator->validate($input, $constraints);

        return ValidationErrorHelper::formatErrors($violations);
    }

    public function validateDbUser($dbUser, ExecutionContextInterface $context): void
    {
        $input = $context->getRoot();
        if (in_array($input['db_type'], ['mysql', 'pgsql'])) {
            if (empty($dbUser)) {
                $context->buildViolation('This value should not be blank.')
                    ->atPath('db_user')
                    ->addViolation();
            } else {
                $maxLength = $input['db_type'] === 'mysql' ? self::MYSQL_MAX_USERNAME_LENGTH : self::PGSQL_MAX_USERNAME_LENGTH;

                if (mb_strlen($dbUser) > $maxLength) {
                    $context->buildViolation("This value is too long. It should have {$maxLength} characters or less.")
                        ->atPath('db_user')
                        ->addViolation();
                }

                $pattern = $input['db_type'] === 'mysql'
                    ? '/^[a-zA-Z0-9_]+$/'
                    : '/^[a-zA-Z_][a-zA-Z0-9_]*$/';

                if (!preg_match($pattern, $dbUser)) {
                    $message = $input['db_type'] === 'mysql'
                        ? 'Username can only contain letters, numbers, and underscores.'
                        : 'Username must start with a letter or underscore, followed by letters, numbers, or underscores.';

                    $context->buildViolation($message)
                        ->atPath('db_user')
                        ->addViolation();
                }
            }
        }
    }

    public function validateDbPass($dbPass, ExecutionContextInterface $context): void
    {
        $input = $context->getRoot();
        if (in_array($input['db_type'], ['mysql', 'pgsql']) && empty($dbPass)) {
            $context->buildViolation('DB password should not be blank.')
                ->atPath('db_pass')
                ->addViolation();
        }

        if (strlen($dbPass) < 8) {
            $context->buildViolation('Password should be at least 8 characters long.')
                ->atPath('db_pass')
                ->addViolation();
        }
    }

    public function validateDbHost($dbHost, ExecutionContextInterface $context): void
    {
        $input = $context->getRoot();
        if (in_array($input['db_type'], ['mysql', 'pgsql']) && empty($dbHost)) {
            $context->buildViolation('DB host should not be blank.')
                ->atPath('db_host')
                ->addViolation();
        }

        if (!filter_var($dbHost, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) &&
            !filter_var($dbHost, FILTER_VALIDATE_IP) &&
            $dbHost !== 'localhost') {
            $context->buildViolation('Invalid hostname or IP address.')
                ->atPath('db_host')
                ->addViolation();
        }
    }

    public function validateDbPort($port, ExecutionContextInterface $context): void
    {
        if (!ctype_digit($port)) {
            $context->buildViolation('Port must be a valid number.')
                ->atPath('db_port')
                ->addViolation();
            return;
        }

        $port = (int) $port;
        if ($port < self::MIN_PORT || $port > self::MAX_PORT) {
            $context->buildViolation('Port must be between {{ min }} and {{ max }}')
                ->setParameter('{{ min }}', self::MIN_PORT)
                ->setParameter('{{ max }}', self::MAX_PORT)
                ->atPath('db_port')
                ->addViolation();
        }
    }

    public function validateDbName($dbName, ExecutionContextInterface $context): void
    {
        $input = $context->getRoot();

        switch ($input['db_type']) {
            case 'mysql':
                if (strlen($dbName) > 64) {
                    $context->buildViolation('MySQL database name cannot exceed 64 characters')
                        ->addViolation();
                    return;
                }
                if (!preg_match('/^[a-zA-Z0-9$_]+$/', $dbName)) {
                    $context->buildViolation('MySQL database name can only contain letters, numbers, $, and underscores')
                        ->addViolation();
                    return;
                }
                break;

            case 'pgsql':
                if (strlen($dbName) > 63) {
                    $context->buildViolation('PostgreSQL database name cannot exceed 63 characters')
                        ->addViolation();
                    return;
                }
                if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $dbName)) {
                    $context->buildViolation('PostgreSQL database name must start with a letter or underscore, followed by letters, numbers, or underscores')
                        ->addViolation();
                    return;
                }
                break;

            case 'sqlite':
                try {
                    if (!file_exists($dbName)) {
                        $context->buildViolation('SQLite database file does not exist')
                            ->addViolation();
                        return;
                    }

                    $realPath = realpath($dbName);
                    if ($realPath === false) {
                        $context->buildViolation('Invalid database path to SQLite file')
                            ->addViolation();
                        return;
                    }
                    $dbName = $realPath;

                    if (!preg_match('/\.(sqlite3?|db3?)$/', $dbName)) {
                        $context->buildViolation('Database file must have a valid SQLite extension (.sqlite, .sqlite3, .db, .db3)')
                            ->addViolation();
                        return;
                    }

                    if (!is_readable($dbName) || !is_writable($dbName)) {
                        $context->buildViolation('SQLite database file must be both readable and writable by the web server')
                            ->addViolation();
                        return;
                    }

                    $pdo = new PDO("sqlite:{$dbName}", null, null, [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_TIMEOUT => 5
                    ]);

                    $version = $pdo->query('SELECT sqlite_version()')->fetchColumn();
                    if (version_compare($version, '3.0.0', '<')) {
                        $context->buildViolation('Unsupported SQLite version (minimum required: 3.0.0)')
                            ->addViolation();
                        return;
                    }

                    $pdo = null;

                } catch (PDOException $e) {
                    $context->buildViolation('Database error: ' . $e->getMessage())
                        ->addViolation();
                    return;
                }
                break;

            default:
                $context->buildViolation('Unsupported database type')
                    ->addViolation();
                return;
        }

        // Test full database connection for MySQL and PostgreSQL
        if (in_array($input['db_type'], ['mysql', 'pgsql'])) {
            if ($error = $this->testDatabaseConnection($input)) {
                $context->buildViolation('Database connection failed: ' . $error)
                    ->addViolation();
            }
        }
    }

    private function testDatabaseConnection(array $input): ?string
    {
        try {
            $dsn = $this->buildDsn($input);
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5,
            ];

            $pdo = new PDO($dsn, $input['db_user'] ?? null, $input['db_pass'] ?? null, $options);
            $pdo->query('SELECT 1');

            return null;
        } catch (PDOException $e) {
            return $e->getMessage();
        }
    }

    private function buildDsn(array $input): string
    {
        switch ($input['db_type']) {
            case 'mysql':
                $port = $input['db_port'] ?? 3306;
                return "mysql:host={$input['db_host']};port={$port};dbname={$input['db_name']}";
            case 'pgsql':
                $port = $input['db_port'] ?? 5432;
                return "pgsql:host={$input['db_host']};port={$port};dbname={$input['db_name']}";
            case 'sqlite':
                return "sqlite:{$input['db_name']}";
            default:
                throw new InvalidArgumentException('Unsupported database type');
        }
    }

    public function validateDbCharset($charset, ExecutionContextInterface $context): void
    {
        // Implementation needed - requires supported charset list
    }

    public function validateDbCollation($collation, ExecutionContextInterface $context): void
    {
        // Implementation needed - requires supported collation list
    }
}
