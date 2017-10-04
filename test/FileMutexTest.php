<?php

    namespace NokitaKaze\Mutex;

    class SmartMutexTest extends \PHPUnit_Framework_TestCase {
        function test__construct() {
            $values1 = [];
            $values2 = [];
            $values3 = [];
            $values4 = [];
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
                        $this->assertNotEquals('', $mutex->filename);
                        $this->assertNotContains($mutex->filename, $values1);
                        $values1[] = $mutex->filename;
                        $this->assertEquals($name1, $mutex->get_mutex_name());
                        $this->assertFalse($mutex->get_delete_on_release());
                    }
                }

                foreach (['', 'prefix_'] as $prefix) {
                    foreach ([null, '/nyan/pasu1', '/foo/bar1'] as &$folder1) {
                        $mutex = new FileMutex([
                            'name' => $name1,
                            'prefix' => $prefix,
                            'folder' => $folder1,
                        ]);
                        $this->assertNotEquals('', $mutex->filename);
                        if (!in_array($mutex->filename, ['/tmp/smartmutex_nyan.lock', '/tmp/smartmutex_pasu.lock'])) {
                            $this->assertNotContains($mutex->filename, $values2);
                            $values2[] = $mutex->filename;
                        }
                        $this->assertEquals($name1, $mutex->get_mutex_name());
                        $this->assertFalse($mutex->get_delete_on_release());
                    }
                }

                foreach ([MutexInterface::DOMAIN,
                          MutexInterface::DIRECTORY,
                          MutexInterface::SERVER] as &$type1) {
                    $mutex = new FileMutex([
                        'name' => $name1,
                        'type' => $type1,
                    ]);
                    $this->assertNotEquals('', $mutex->filename);
                    $this->assertNotContains($mutex->filename, $values3);
                    $values3[] = $mutex->filename;
                    $this->assertEquals($name1, $mutex->get_mutex_name());
                    $this->assertFalse($mutex->get_delete_on_release());
                }

                {
                    $mutex = new FileMutex([
                        'name' => $name1,
                    ]);
                    $this->assertNotEquals('', $mutex->filename);
                    $this->assertNotContains($mutex->filename, $values4);
                    $values4[] = $mutex->filename;
                    $this->assertEquals($name1, $mutex->get_mutex_name());
                    $this->assertFalse($mutex->get_delete_on_release());
                }
            }
        }

        function testDelete_on_release() {
            do {
                $folder = sys_get_temp_dir().'/nkt_mutex_test_';
                for ($i = 0; $i < 10; $i++) {
                    $folder .= chr(mt_rand(ord('a'), ord('z')));
                }
            } while (file_exists($folder));
            $mutex = new FileMutex([
                'name' => 'foobar',
                'type' => MutexInterface::SERVER,
                'delete_on_release' => true,
                'folder' => $folder,
            ]);
            $this->assertTrue($mutex->get_delete_on_release());
            $this->assertTrue($mutex->get_lock(0));
            $this->assertTrue($mutex->get_lock(0));
            $this->assertTrue($mutex->is_acquired());
            $mutex->release_lock();
            $this->assertFileNotExists($mutex->filename);
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
                if (!in_array(FileMutex::getDirectoryString(), ['none', '/dev/shm/nyanpasu'])) {
                    $this->assertFileExists(FileMutex::getDirectoryString());
                    $this->assertTrue(is_dir(FileMutex::getDirectoryString()));
                }
                unset($_SERVER[$key]);
            }
            $this->assertNotNull(FileMutex::getDirectoryString());
            $this->assertNotEquals('', FileMutex::getDomainString());
            if (!in_array(FileMutex::getDirectoryString(), ['none', '/dev/shm/nyanpasu'])) {
                $this->assertFileExists(FileMutex::getDirectoryString());
                $this->assertTrue(is_dir(FileMutex::getDirectoryString()));
            }

            $_SERVER = $server_original;
        }

        // @todo проверять, что type меняется правильно, когда он SERVER, DIRECTORY & DOMAIN
        // Он должен менять мьютекс, когда отличается домен, если DOMAIN

        function testGet_lock() {
            do {
                $folder = sys_get_temp_dir().'/nkt_mutex_test_';
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
            $ts1 = microtime(true);
            $this->assertTrue($mutex->get_lock(0));
            $reflection = new \ReflectionProperty($mutex, '_file_handler');
            $reflection->setAccessible(true);
            $this->assertNotFalse($reflection->getValue($mutex));
            $this->assertNotNull($reflection->getValue($mutex));
            $this->assertTrue($mutex->is_acquired());
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
            $this->assertFalse($mutex1->is_acquired());
            //
            $mutex1 = new FileMutex([
                'name' => 'nyan',
                'type' => MutexInterface::SERVER,
                'folder' => $folder,
            ]);
            $this->assertFalse($mutex1->get_lock(2));
            $this->assertFalse($mutex1->is_acquired());

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
            $this->assertTrue($mutex->is_acquired());

            $mutex->release_lock();
            $reflection = new \ReflectionProperty($mutex, '_file_handler');
            $reflection->setAccessible(true);
            $this->assertFalse($mutex->is_acquired());
            $this->assertFalse($reflection->getValue($mutex));
            $this->assertFileExists($mutex->filename);

            $mutex = new FileMutex([
                'name' => 'pasu',
                'type' => MutexInterface::SERVER,
                'folder' => $folder,
            ]);
            $this->assertTrue($mutex->get_lock(0));
            $this->assertTrue($mutex->get_lock(0));
            $this->assertTrue($mutex->is_acquired());
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
                $folder = sys_get_temp_dir().'/nkt_mutex_test_';
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
            $this->assertTrue($mutex->is_acquired());
            $this->assertFalse($mutex->is_free());
            $mutex1 = new FileMutex([
                'name' => 'nyan',
                'type' => MutexInterface::SERVER,
                'folder' => $folder,
            ]);
            $this->assertFalse($mutex1->is_free());
            $mutex->release_lock();
            $this->assertTrue($mutex->is_free());
            $this->assertFalse($mutex->is_acquired());
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

        function dataCreate_folders_in_path() {
            $tmp_folder = sys_get_temp_dir();

            $data = [
                [$tmp_folder.'/nkt_mutex_test_'.mt_rand(1, 1000000), null],
                [$tmp_folder.'/nkt_mutex_test_'.mt_rand(1, 1000000).'/'.mt_rand(0, 1000000).'/'.mt_rand(0, 1000000), null],
            ];

            $folder = $tmp_folder.'/nkt_mutex_test_'.mt_rand(1, 1000000).'/'.mt_rand(0, 1000000);
            $postfix = mt_rand(0, 1000000);
            $data[] = [$folder.'/./'.$postfix, $folder.'/'.$postfix];

            $folder = $tmp_folder.'/nkt_mutex_test_'.mt_rand(1, 1000000).'/'.mt_rand(0, 1000000);
            $postfix = mt_rand(0, 1000000);
            $data[] = [$folder.'/'.mt_rand(0, 1000000).'/../'.$postfix, $folder.'/'.$postfix];

            $folder = $tmp_folder.'/nkt_mutex_test_'.mt_rand(1, 1000000).'/'.mt_rand(0, 1000000);
            $postfix = mt_rand(0, 1000000);
            $data[] = [$folder.'/'.mt_rand(0, 1000000).'/'.mt_rand(0, 1000000).'/../../'.$postfix, $folder.'/'.$postfix];

            return $data;
        }

        /**
         * @param string $string
         * @param string $real_path
         *
         * @dataProvider dataCreate_folders_in_path
         */
        function testCreate_folders_in_path($string, $real_path = null) {
            if (is_null($real_path)) {
                $real_path = $string;
            }
            FileMutex::create_folders_in_path($string);
            $this->assertFileExists($real_path);
            $this->assertTrue(is_dir($real_path));
            exec(sprintf('rm -rf %s', escapeshellarg($real_path)));
        }

        function testCreate_folders_in_path_exception1() {
            if (file_exists('/proc')) {
                $folder = '/proc/nkt_mutex_test_'.mt_rand(1, 1000000);
            } else {
                $folder = '/root/nkt_mutex_test_'.mt_rand(1, 1000000);
            }

            $u = false;
            try {
                FileMutex::create_folders_in_path($folder);
            } catch (MutexException $e) {
                $u = true;
            }
            $this->assertTrue($u, 'FileMutex did not raise exception on non writable folder');
            $this->assertFileNotExists($folder);
        }

        function testCreate_folders_in_path_exception2() {
            if (file_exists('/proc')) {
                $filename = sys_get_temp_dir().'/nkt_mutex_test_'.mt_rand(1, 1000000).'.tmp';
            } else {
                $filename = sys_get_temp_dir().'/nkt_mutex_test_'.mt_rand(1, 1000000).'.tmp';
            }
            $this->assertTrue(touch($filename));

            $u = false;
            try {
                FileMutex::create_folders_in_path($filename.'/nkt_mutex_test_'.mt_rand(1, 1000000));
            } catch (MutexException $e) {
                $u = true;
            }
            $this->assertTrue($u, 'FileMutex did not raise exception on file in filepath');
        }

        /**
         * @covers \NokitaKaze\Mutex\FileMutex::get_last_php_error_as_string
         */
        function testGet_last_php_error_as_string() {
            if (PHP_MAJOR_VERSION >= 7) {
                error_clear_last();
                $this->assertEmpty(FileMutex::get_last_php_error_as_string());
            }

            @trigger_error('Nyan Pasu Test Mutex');
            $this->assertRegExp('_Nyan Pasu Test Mutex_', FileMutex::get_last_php_error_as_string());
        }
    }

?>