<?php

namespace Haoa\MixDatabase;

/**
 * Interface LoggerInterface
 * @package Haoa\MixDatabase
 */
interface LoggerInterface
{

    public function trace(float $time, string $sql, array $bindings, int $rowCount, ?\Throwable $exception): void;

}