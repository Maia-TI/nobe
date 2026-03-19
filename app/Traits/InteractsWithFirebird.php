<?php

namespace App\Traits;

use PDO;
use Exception;
use Illuminate\Support\Facades\Log;

trait InteractsWithFirebird
{
    protected ?PDO $firebird = null;

    protected function initializeFirebird(?string $companyCode = null): PDO
    {
        if ($this->firebird) {
            return $this->firebird;
        }

        if ($companyCode) {
            $details = (object) [
                'HOST' => '142.93.207.205',
                'PORT' => '3050',
                'DATABASE_PATH' => '/home/99a75dc56eb246fcbbacd152b04a9bc7/pontapedras.fdb',
                'USERNAME' => 'JANELAMAIATI',
                'PASSWORD' => 'mastermaia',
            ];

            $host = $details->HOST;
            $port = $details->PORT;
            $database = $details->DATABASE_PATH;
            $username = $details->USERNAME;
            $password = $details->PASSWORD;

            $dsn = "firebird:dbname={$host}/{$port}:{$database};charset=UTF8";
            return $this->createPdoInstance($dsn, $username, $password);
        }
    }

    protected function createPdoInstance(string $dsn, string $username, string $password): PDO
    {
        return retry(5, function ($attempt) use ($dsn, $username, $password) {
            try {
                $this->firebird = new PDO($dsn, $username, $password, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
                ]);
                return $this->firebird;
            } catch (Exception $e) {
                // Check for Firebird connection shutdown error (code 335544856)
                if ($e->getCode() == 335544856 || str_contains($e->getMessage(), 'connection shutdown')) {
                    if (isset($this->command)) {
                        $this->warn("Firebird: Connection shutdown detected (Attempt {$attempt}). Running GC and waiting...");
                    }
                    gc_collect_cycles();
                }
                throw $e;
            }
        }, 2000);
    }

    protected function getFirebirdConnection(): PDO
    {
        return $this->initializeFirebird();
    }
}
