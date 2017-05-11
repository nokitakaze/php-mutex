<?php

    namespace NokitaKaze\Mutex;

    class SmartMutexTest extends \PHPUnit_Framework_TestCase {
        function test__construct() {
            $values = [];
            foreach (['nyan', 'pasu'] as &$name1) {
                foreach ([MutexInterface::DOMAIN,
                          MutexInterface::DIRECTORY,
                          MutexInterface::SERVER] as &$type1) {
                    foreach ([null, '/nyan/pasu', '/foo/bar'] as &$folder1) {
                        $mutex = new FileMutex([
                            'name' => $name1,
                            'type' => $type1,
                            'folder' => $folder1,
                        ]);
                        $this->assertNotContains($mutex->filename, $values);
                        $this->assertEquals($name1, $mutex->get_mutex_name());
                        $this->assertFalse($mutex->get_delete_on_release());
                    }
                }
            }
            unset($values);
        }

        /**
         * @backupGlobals
         */
        function testGetDomainString() {
            if (!isset($_SERVER['HTTP_HOST'])) {
                $_SERVER['HTTP_HOST'] = 'example.com';
                $this->assertEquals('example.com', FileMutex::getDomainString());
            } else {
                $this->assertNotEquals('', FileMutex::getDomainString());
            }
            if (!isset($_SERVER['HOSTNAME'])) {
                $_SERVER['HOSTNAME'] = 'example.com';
            }
            $this->assertNotEquals('', FileMutex::getDomainString());
            $_SERVER['HTTP_HOST'] = 'example.com';
            foreach (['HTTP_HOST', 'HOSTNAME', 'SCRIPT_NAME'] as $id => $key) {
                $this->assertNotEquals('', FileMutex::getDomainString());
                unset($_SERVER[$key]);
            }
            $this->assertNotNull(FileMutex::getDomainString());
            $this->assertNotEquals('', FileMutex::getDomainString());
        }

        function testGetDirectoryString() {
            $server_original = $_SERVER;
            $_SERVER['DOCUMENT_ROOT'] = '/dev/shm/nyanpasu';
            $this->assertEquals('/dev/shm/nyanpasu', FileMutex::getDirectoryString());
            $_SERVER['DOCUMENT_ROOT'] = '';
            $this->assertNotEquals('', FileMutex::getDirectoryString());
            $_SERVER['DOCUMENT_ROOT'] = '/dev/shm/nyanpasu';
            foreach (['DOCUMENT_ROOT', 'PWD', 'SCRIPT_NAME'] as $id => $key) {
                $this->assertNotEquals('', FileMutex::getDirectoryString());
                unset($_SERVER[$key]);
            }
            $this->assertNotNull(FileMutex::getDirectoryString());
            $this->assertNotEquals('', FileMutex::getDomainString());

            $_SERVER = $server_original;
        }

        function testGet_lock() {
            do {
                $folder = sys_get_temp_dir().'/nkt_test_';
                for ($i = 0; $i < 10; $i++) {
                    $folder .= chr(mt_rand(ord('a'), ord('z')));
                }
            } while (file_exists($folder));
            $mutex = new FileMutex([
                'name' => 'nyan',
                'type' => MutexInterface::SERVER,
                'folder' => $folder,
            ]);

            //
            $used_error_codes = [];
            $u = false;
            try {
                $mutex->get_lock(1);
            } catch (MutexException $e) {
                $u = true;
                $used_error_codes[] = $e->getCode();
            }
            if (!$u) {
                $this->fail('SmartMutex did not throw exception on non existed folder');
            }
            //
            mkdir($folder);
            chmod($folder, 0);
            $u = false;
            try {
                $mutex->get_lock(1);
            } catch (MutexException $e) {
                $u = true;
            }
            if (!$u) {
                @rmdir($folder);
                $this->fail('SmartMutex did not throw exception on non writable folder');
            }
            //
            chmod($folder, 7 << 6);
            touch($mutex->filename);
            chmod($mutex->filename, 0);
            $u = false;
            try {
                $mutex->get_lock(1);
            } catch (MutexException $e) {
                $u = true;
                $this->assertNotContains($e->getCode(), $used_error_codes);
                $used_error_codes[] = $e->getCode();
            }
            if (!$u) {
                @unlink($mutex->filename);
                @rmdir($folder);
                $this->fail('SmartMutex did not throw exception on non writable file');
            }
            chmod($mutex->filename, 7 << 6);
            //
            $lock_acquired = new \ReflectionProperty($mutex, '_lock_acquired');
            $lock_acquired->setAccessible(true);
            $ts1 = microtime(true);
            $this->assertTrue($mutex->get_lock(0));
            $reflection = new \ReflectionProperty($mutex, '_file_handler');
            $reflection->setAccessible(true);
            $this->assertNotFalse($reflection->getValue($mutex));
            $this->assertNotNull($reflection->getValue($mutex));
            $this->assertTrue($lock_acquired->getValue($mutex));
            $this->assertTrue($mutex->get_lock(0));
            $this->assertNotNull($mutex->get_acquired_time());
            $this->assertLessThan(microtime(true), $mutex->get_acquired_time());
            $this->assertGreaterThan($ts1, $mutex->get_acquired_time());

            //
            $mutex1 = new FileMutex([
                'name' => 'nyan',
                'type' => MutexInterface::SERVER,
                'folder' => $folder,
            ]);
            $this->assertFalse($mutex1->get_lock(0.01));
            $this->assertFalse($lock_acquired->getValue($mutex1));
            //
            $mutex1 = new FileMutex([
                'name' => 'nyan',
                'type' => MutexInterface::SERVER,
                'folder' => $folder,
            ]);
            $this->assertFalse($mutex1->get_lock(2));

            //
            for ($i = 0; $i < 5; $i++) {
                $r = mt_rand(10, 50) / 10;
                $ts1 = microtime(true);
                $this->assertFalse($mutex1->get_lock($r));
                $ts2 = microtime(true);
                $this->assertGreaterThanOrEqual($r, $ts2 - $ts1);
                $this->assertLessThanOrEqual($r + 0.1, $ts2 - $ts1);
            }

            // @todo Проверить get_lock(-1), это невозможно в одном потоке

            @unlink($mutex->filename);
            @rmdir($folder);

            $filename = tempnam(sys_get_temp_dir(), 'ascetcms_mutex_test_');
            $mutex = new FileMutex([
                'name' => 'nyan',
                'type' => MutexInterface::SERVER,
                'folder' => $filename,
            ]);
            $u = false;
            try {
                $mutex->get_lock();
            } catch (MutexException $e) {
                $u = true;
                $this->assertNotContains($e->getCode(), $used_error_codes);
                $used_error_codes[] = $e->getCode();
            }
            unlink($filename);
            if (!$u) {
                $this->fail('SmartMutex did not throw exception on non writable file');
            }
        }

        function testRelease_lock() {
            do {
                $folder = sys_get_temp_dir().'/ascetcms_test_';
                for ($i = 0; $i < 10; $i++) {
                    $folder .= chr(mt_rand(ord('a'), ord('z')));
                }
            } while (file_exists($folder));
            mkdir($folder);
            $mutex = new FileMutex([
                'name' => 'nyan',
                'type' => MutexInterface::SERVER,
                'folder' => $folder,
            ]);
            $this->assertTrue($mutex->get_lock(0));

            $mutex->release_lock();
            $reflection = new \ReflectionProperty($mutex, '_file_handler');
            $reflection->setAccessible(true);
            $lock_acquired = new \ReflectionProperty($mutex, '_lock_acquired');
            $lock_acquired->setAccessible(true);
            $this->assertFalse($lock_acquired->getValue($mutex));
            $this->assertFalse($reflection->getValue($mutex));
            $this->assertFileExists($mutex->filename);

            $mutex = new FileMutex([
                'name' => 'pasu',
                'type' => MutexInterface::SERVER,
                'folder' => $folder,
            ]);
            $this->assertTrue($mutex->get_lock(0));
            $this->assertTrue($mutex->get_lock(0));
            $delete_on_release = new \ReflectionProperty($mutex, '_delete_on_release');
            $delete_on_release->setAccessible(true);
            $delete_on_release->setValue($mutex, true);
            $mutex->release_lock();
            $this->assertFileNotExists($mutex->filename);

            @unlink($mutex->filename);
            @rmdir($folder);
        }

        function testIs_free() {
            do {
                $folder = sys_get_temp_dir().'/nkt_test_';
                for ($i = 0; $i < 10; $i++) {
                    $folder .= chr(mt_rand(ord('a'), ord('z')));
                }
            } while (file_exists($folder));
            $mutex = new FileMutex([
                'name' => 'nyan',
                'type' => MutexInterface::SERVER,
                'folder' => $folder,
            ]);
            //
            $u = false;
            $used_error_codes = [];
            try {
                $mutex->is_free();
            } catch (MutexException $e) {
                $u = true;
                $used_error_codes[] = $e->getCode();
            }
            if (!$u) {
                $this->fail('SmartMutex did not throw exception on non existed folder');
            }
            //
            mkdir($folder);
            chmod($folder, 0);
            $u = false;
            try {
                $mutex->is_free();
            } catch (MutexException $e) {
                $u = true;
                $this->assertNotContains($e->getCode(), $used_error_codes);
                $used_error_codes[] = $e->getCode();
            }
            if (!$u) {
                @rmdir($folder);
                $this->fail('SmartMutex did not throw exception on non writable folder');
            }
            //
            chmod($folder, 7 << 6);
            touch($mutex->filename);
            chmod($mutex->filename, 0);
            $u = false;
            try {
                $mutex->is_free();
            } catch (MutexException $e) {
                $u = true;
                $this->assertNotContains($e->getCode(), $used_error_codes);
                $used_error_codes[] = $e->getCode();
            }
            if (!$u) {
                @unlink($mutex->filename);
                @rmdir($folder);
                $this->fail('SmartMutex did not throw exception on non writable file');
            }
            chmod($mutex->filename, 7 << 6);

            //
            $mutex = new FileMutex([
                'name' => 'nyan',
                'type' => MutexInterface::SERVER,
                'folder' => $folder,
            ]);
            $this->assertTrue($mutex->is_free());
            $this->assertFileExists($mutex->filename);
            $mutex->get_lock(0);
            $this->assertFalse($mutex->is_free());
            $mutex1 = new FileMutex([
                'name' => 'nyan',
                'type' => MutexInterface::SERVER,
                'folder' => $folder,
            ]);
            $this->assertFalse($mutex1->is_free());
            $mutex->release_lock();
            $this->assertTrue($mutex->is_free());
            unlink($mutex->filename);

            //
            @unlink($mutex->filename);
            @rmdir($folder);

            $filename = tempnam(sys_get_temp_dir(), 'nkt_mutex_test_');
            $mutex = new FileMutex([
                'name' => 'nyan',
                'type' => MutexInterface::SERVER,
                'folder' => $filename,
            ]);
            $u = false;
            try {
                $mutex->is_free();
            } catch (MutexException $e) {
                $u = true;
                $this->assertNotContains($e->getCode(), $used_error_codes);
                $used_error_codes[] = $e->getCode();
            }
            unlink($filename);
            if (!$u) {
                $this->fail('SmartMutex did not throw exception on non writable file');
            }
        }
    }

?>