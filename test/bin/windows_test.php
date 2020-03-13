<?php
    require_once __DIR__.'/../../src/MutexInterface.php';
    require_once __DIR__.'/../../src/MutexException.php';
    require_once __DIR__.'/../../src/FileMutex.php';

    if (DIRECTORY_SEPARATOR !== '\\') {
        throw new \Exception('This is not a Windows');
    }

    $folder1 = sys_get_temp_dir().'/nkt_mutex_test';
    // #1
    $mutex1 = new \NokitaKaze\Mutex\FileMutex([
        'folder' => $folder1,
        'name' => 'test1',
    ]);

    if (!$mutex1->get_lock()) {
        throw new \Exception('Can not get lock #1 on file');
    }

    // #2
    $mutex2 = new \NokitaKaze\Mutex\FileMutex([
        'folder' => $folder1,
        'name' => 'test1',
    ]);
    if ($mutex2->get_lock(0)) {
        throw new \Exception('Double locked file');
    }

    // #3
    $mutex1->release_lock();
    if (!$mutex2->get_lock()) {
        throw new \Exception('Can not get lock #2 on file');
    }

    echo "done\n";
?>