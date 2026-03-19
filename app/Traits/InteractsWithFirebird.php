<?php

namespace App\Traits;

use PDO;
use Exception;
use Illuminate\Support\Facades\Log;

trait InteractsWithFirebird
{
    protected ?PDO $firebird = null;

    protected function loadFirebirdConnectionDetails(string $companyCode)
    {
        $cacheKey = "firebird:conn_details:{$companyCode}";

        return \Illuminate\Support\Facades\Cache::remember(
            $cacheKey,
            now()->addHours(12),
            function () use ($companyCode) {
                // O "Main PDO" aqui é a conexão padrão do Laravel (PostgreSQL)
                $pdo = \Illuminate\Support\Facades\DB::connection()->getPdo();

                $stmt = $pdo->prepare('SELECT * FROM VERCONEXAO_2(?)');
                $stmt->execute([$companyCode]);

                $result = $stmt->fetch(PDO::FETCH_OBJ);

                if (!$result) {
                    throw new Exception("Detalhes de conexão não encontrados para a empresa {$companyCode}");
                }

                // Esperamos que o resultado tenha campos como HOST, PORT, DATABASE, USERNAME, PASSWORD, etc.
                // Mas de acordo com o snippet do usuário, ele é usado em createPdoInstance(string $dbPath)
                return $result;
            }
        );
    }

    protected function initializeFirebird(?string $companyCode = null): PDO
    {
        if ($this->firebird) {
            return $this->firebird;
        }

        if ($companyCode) {
            $details = $this->loadFirebirdConnectionDetails($companyCode);
            
            // Usando os detalhes retornados pela procedure. 
            // Nota: Ajustar os campos conforme o retorno real da VERCONEXAO_2
            $host = $details->HOST ?? env('FB_DB_HOST');
            $port = $details->PORT ?? env('FB_DB_PORT', 3050);
            $database = $details->DATABASE_PATH ?? $details->CAMINHO ?? env('FB_DB_DATABASE');
            $username = $details->USERNAME ?? env('FB_DB_USERNAME');
            $password = $details->PASSWORD ?? env('FB_DB_PASSWORD');
            $charset = env('FB_DB_CHARSET', 'UTF8');

            $dsn = "firebird:dbname={$host}/{$port}:{$database};charset={$charset}";
            return $this->createPdoInstance($dsn, $username, $password);
        }

        // Fallback para .env se nenhum código de empresa for passado
        $host = env('FB_DB_HOST');
        $port = env('FB_DB_PORT', 3050);
        $database = env('FB_DB_DATABASE');
        $username = env('FB_DB_USERNAME');
        $password = env('FB_DB_PASSWORD');
        $charset = env('FB_DB_CHARSET', 'UTF8');

        $dsn = "firebird:dbname={$host}/{$port}:{$database};charset={$charset}";

        return $this->createPdoInstance($dsn, $username, $password);
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

    protected function recordExists(string $table, string $primaryKey, $value): bool
    {
        $pdo = $this->getFirebirdConnection();
        $stmt = $pdo->prepare("SELECT 1 FROM {$table} WHERE {$primaryKey} = ?");
        $stmt->execute([$value]);
        return $stmt->fetch() !== false;
    }

    protected function insertIntoFirebird(string $table, array $data): bool
    {
        $pdo = $this->getFirebirdConnection();
        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        
        $sql = "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})";
        $stmt = $pdo->prepare($sql);
        return $stmt->execute(array_values($data));
    }

    protected function updateInFirebird(string $table, string $primaryKey, $id, array $data): bool
    {
        $pdo = $this->getFirebirdConnection();
        $sets = [];
        foreach (array_keys($data) as $column) {
            $sets[] = "{$column} = ?";
        }
        $setsStr = implode(', ', $sets);
        
        $sql = "UPDATE {$table} SET {$setsStr} WHERE {$primaryKey} = ?";
        $stmt = $pdo->prepare($sql);
        
        $values = array_values($data);
        $values[] = $id;
        
        return $stmt->execute($values);
    }
}
