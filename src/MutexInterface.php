<?php declare(strict_types=1);

namespace NokitaKaze\Mutex;

interface MutexInterface
{
    const DOMAIN = 0;// В пределах одного домена
    const DIRECTORY = 1;// В пределах одной папки DOCUMENT_ROOT
    const SERVER = 2;// All virtual hosts on server with one tmp-folder e.g. "/tmp"

    /**
     * MutexInterface constructor.
     *
     * @param MutexSettings|array $settings
     */
    public function __construct($settings);

    /**
     * @param integer|double $time
     *
     * @return bool
     */
    public function get_lock(float $time = -1): bool;

    public function release_lock();

    /**
     * @return boolean
     */
    public function is_free(): bool;

    /**
     * @return boolean
     */
    public function is_acquired(): bool;
}
