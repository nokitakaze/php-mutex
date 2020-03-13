<?php

    namespace NokitaKaze\Mutex;

    class FileMutex implements MutexInterface
    {
        /**
         * @var string Название файла, на котором будет работать мьютекс
         */
        var $filename = '';
        protected $_mutex_type;
        /**
         * @var string|null Название мьютекса, должно состоять из валидных для имени файлов символов
         */
        var $_mutex_name;
        /**
         * @var string|null Место, где расположен файл.
         * Не используется в логике класса
         */
        protected $_mutex_folder;
        /**
         * @var double|null Время, когда мьютекс был получен
         */
        protected $_lock_acquired_time = null;
        /**
         * @var boolean Текущее состояние мьютекса
         */
        protected $_lock_acquired = false;
        /**
         * @var resource|false Файл, открывающийся через fopen
         */
        protected $_file_handler = false;
        /**
         * @var boolean Удалять файл при анлоке
         * В данный момент это unsafe поведение, из-за немандаторного доступа к файлам из PHP
         */
        protected $_delete_on_release = false;

        /**
         * @param MutexSettings|array $settings
         */
        function __construct($settings)
        {
            /**
             * @var MutexSettings $settings
             */
            $settings = (object)$settings;
            if (!isset($settings->type)) {
                $settings->type = null;
            }
            if (!isset($settings->folder)) {
                $settings->folder = null;
            }

            $this->_mutex_type = is_null($settings->type) ? self::SERVER : $settings->type;
            $this->_mutex_folder = is_null($settings->folder) ? sys_get_temp_dir() : $settings->folder;
            $this->_mutex_name = $settings->name;
            if (isset($settings->delete_on_release)) {
                $this->_delete_on_release = $settings->delete_on_release;
            }

            $prefix = '';
            if (isset($settings->prefix)) {
                $prefix = $settings->prefix;
            } elseif ($this->_mutex_type == self::DOMAIN) {
                $prefix = hash('sha512', self::getDomainString()).'_';
            } elseif ($this->_mutex_type == self::DIRECTORY) {
                $prefix = hash('sha512', strtolower(self::getDirectoryString())).'_';
            }
            $this->filename = $this->_mutex_folder.DIRECTORY_SEPARATOR.'smartmutex_'.$prefix.$this->_mutex_name.'.lock';
        }

        function __destruct()
        {
            $this->release_lock();
        }

        /**
         * @return string
         */
        static function getDomainString()
        {
            if (isset($_SERVER['HTTP_HOST']) and ($_SERVER['HTTP_HOST'] != '')) {
                return $_SERVER['HTTP_HOST'];
            } elseif (gethostname() != '') {
                return gethostname();
            } elseif (isset($_SERVER['SCRIPT_NAME'])) {
                return $_SERVER['SCRIPT_NAME'];
            } else {
                return 'none';
            }
        }

        /**
         * @return string
         */
        static function getDirectoryString()
        {
            if (isset($_SERVER['DOCUMENT_ROOT']) and ($_SERVER['DOCUMENT_ROOT'] != '')) {
                return $_SERVER['DOCUMENT_ROOT'];
            } elseif (isset($_SERVER['PWD']) and ($_SERVER['PWD'] != '')) {
                return $_SERVER['PWD'];
            } elseif (isset($_SERVER['SCRIPT_NAME'])) {
                return dirname($_SERVER['SCRIPT_NAME']);
            } else {
                return 'none';
            }
        }

        /**
         * @return boolean
         *
         * @throws MutexException
         */
        function is_free()
        {
            if ($this->_lock_acquired) {
                return false;
            }
            if (!file_exists(dirname($this->filename))) {
                throw new MutexException('Folder "'.dirname($this->filename).'" does not exist', 1);
            } elseif (!is_dir(dirname($this->filename))) {
                throw new MutexException('Folder "'.dirname($this->filename).'" does not exist', 4);
            } elseif (!is_writable(dirname($this->filename))) {
                throw new MutexException('Folder "'.dirname($this->filename).'" is not writable', 2);
            } elseif (file_exists($this->filename) and !is_writable($this->filename)) {
                throw new MutexException('File "'.$this->filename.'" is not writable', 3);
            }

            $fo = fopen($this->filename, 'ab');
            $result = flock($fo, LOCK_EX | LOCK_NB);
            flock($fo, LOCK_UN);
            fclose($fo);

            return $result;
        }

        /**
         * @param double|integer $time
         *
         * @return bool
         * @throws MutexException
         */
        function get_lock($time = -1)
        {
            $tmp_time = microtime(true);
            if ($this->_lock_acquired) {
                return true;
            }

            self::create_folders_in_path(dirname($this->filename));
            if (!is_dir(dirname($this->filename))) {
                throw new MutexException('Folder "'.dirname($this->filename).'" is not a folder', 4);
            } elseif (!is_writable(dirname($this->filename))) {
                throw new MutexException('Folder "'.dirname($this->filename).'" is not writable', 2);
            } elseif (file_exists($this->filename) and !is_writable($this->filename)) {
                throw new MutexException('File "'.$this->filename.'" is not writable', 3);
            }

            // Открываем файл
            $this->_file_handler = fopen($this->filename, file_exists($this->filename) ? 'ab' : 'wb');
            while (($this->_file_handler === false) and (
                    ($tmp_time + $time >= microtime(true)) or ($time == -1)
                )) {
                // Active locks. Yes, this is programming language we have
                usleep(10000);
                $this->_file_handler = fopen($this->filename, 'ab');
            }
            if ($this->_file_handler === false) {
                return false;
            }

            // Блочим файл
            if ($time >= 0) {
                $result = flock($this->_file_handler, LOCK_EX | LOCK_NB);
                while (!$result and ($tmp_time + $time >= microtime(true))) {
                    usleep(10000);
                    $result = flock($this->_file_handler, LOCK_EX | LOCK_NB);
                }
            } else {
                $result = flock($this->_file_handler, LOCK_EX);
            }

            if ($result) {
                $this->_lock_acquired_time = microtime(true);
                fwrite($this->_file_handler, self::getpid()."\n".microtime(true)."\n".self::getuid()."\n\n");
                fflush($this->_file_handler);
                $this->_lock_acquired = true;
            } else {
                fclose($this->_file_handler);
                $this->_file_handler = false;
            }

            return $result;
        }

        function release_lock()
        {
            if (!$this->_lock_acquired) {
                return;
            }
            if (is_resource($this->_file_handler)) {// @hint По неизвестным причинам это не always true condition
                flock($this->_file_handler, LOCK_UN);
                fclose($this->_file_handler);
            }
            $this->_file_handler = false;
            $this->_lock_acquired = false;
            $this->_lock_acquired_time = null;

            if ($this->_delete_on_release and file_exists($this->filename)) {
                @unlink($this->filename);
            }
        }

        /**
         * @return double|null
         */
        function get_acquired_time()
        {
            return $this->_lock_acquired_time;
        }

        /**
         * @return string
         */
        function get_mutex_name()
        {
            return $this->_mutex_name;
        }

        /**
         * @return boolean
         */
        function get_delete_on_release()
        {
            return $this->_delete_on_release;
        }

        /**
         * @return boolean
         */
        function is_acquired()
        {
            return $this->_lock_acquired;
        }

        /**
         * @return string
         */
        static function get_last_php_error_as_string()
        {
            $error = error_get_last();
            if (empty($error)) {
                return '';
            }

            return sprintf('%s%s', $error['message'],
                isset($error['code']) ? ' (#'.$error['code'].')' : '');
        }

        /**
         * @param string $path
         *
         * @return string
         */
        static function sanify_path($path)
        {
            $path = rtrim(str_replace('\\', '/', $path), '/').'/';
            do {
                $old_path = $path;
                $path = str_replace('//', '/', str_replace('/./', '/', $path));
            } while ($path != $old_path);
            do {
                $path = preg_replace('_/([^/]+?)/\\.\\./_', '/', $path, -1, $count);
            } while ($count > 0);
            $path = rtrim($path, '/');

            return $path;
        }

        /**
         * @param string $path
         *
         * @throws \NokitaKaze\Mutex\MutexException
         */
        static function create_folders_in_path($path)
        {
            $chunks = explode('/', self::sanify_path($path));
            $full_path = '';
            foreach ($chunks as $chunk) {
                $full_path = str_replace('//', '/', $full_path.'/'.$chunk);
                if (DIRECTORY_SEPARATOR === '\\') {
                    // Удаляем ведущие слеши на Windows. Не важно абсолютный путь или относительный,
                    // но ведущий слеш надо удалять
                    $full_path = ltrim($full_path, '/');
                }
                // @hint warning всё равно пойдет в error handler
                if (!file_exists($full_path) and !@mkdir($full_path)) {
                    if (!file_exists($full_path)) {
                        // Синхронизация, она такая, да
                        throw new MutexException('Can not create folder: '.self::get_last_php_error_as_string());
                    }
                } elseif (!is_dir($full_path)) {
                    throw new MutexException($full_path.' is not a directory');
                }
            }
        }

        static function getpid()
        {
            return function_exists('posix_getpid') ? posix_getpid() : getmypid();
        }

        static function getuid()
        {
            return function_exists('posix_getuid') ? posix_getuid() : getmyuid();
        }
    }

?>