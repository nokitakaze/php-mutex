<?php

    namespace NokitaKaze\Mutex;

    class FileMutex implements MutexInterface {
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
        function __construct($settings) {
            /**
             * @var MutexSettings $settings
             */
            $settings = (object) $settings;
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
            if ($this->_mutex_type == self::SERVER) {
                $prefix = hash('sha512', self::getDomainString()).'_';
            } elseif ($this->_mutex_type == self::DIRECTORY) {
                $prefix = hash('sha512', strtolower(self::getDirectoryString())).'_';
            }
            $this->filename = $this->_mutex_folder.DIRECTORY_SEPARATOR.'smartmutex_'.$prefix.$this->_mutex_name.'.lock';
        }

        function __destruct() {
            $this->release_lock();
        }

        /**
         * @return string
         */
        static function getDomainString() {
            if (isset($_SERVER['HTTP_HOST']) and ($_SERVER['HTTP_HOST'] != '')) {
                return $_SERVER['HTTP_HOST'];
            } elseif (isset($_SERVER['HOSTNAME']) and ($_SERVER['HOSTNAME'] != '')) {
                return $_SERVER['HOSTNAME'];
            } elseif (isset($_SERVER['SCRIPT_NAME'])) {
                return $_SERVER['SCRIPT_NAME'];
            } else {
                return 'none';
            }
        }

        /**
         * @return string
         */
        static function getDirectoryString() {
            if (isset($_SERVER['DOCUMENT_ROOT']) and ($_SERVER['DOCUMENT_ROOT'] != '')) {
                return $_SERVER['DOCUMENT_ROOT'];
            } elseif (isset($_SERVER['PWD']) and ($_SERVER['PWD'] != '')) {
                return $_SERVER['PWD'];
            } elseif (isset($_SERVER['SCRIPT_NAME'])) {
                return $_SERVER['SCRIPT_NAME'];
            } else {
                return 'none';
            }
        }

        /**
         * @return boolean
         *
         * @throws MutexException
         */
        function is_free() {
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
        function get_lock($time = -1) {
            $tmp_time = microtime(true);
            if ($this->_lock_acquired) {
                return true;
            }

            if (!file_exists(dirname($this->filename))) {
                throw new MutexException('Folder "'.dirname($this->filename).'" does not exist', 1);
            } elseif (!is_dir(dirname($this->filename))) {
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
                    // U MAD?
                    usleep(10000);
                    $result = flock($this->_file_handler, LOCK_EX | LOCK_NB);
                }
            } else {
                $result = flock($this->_file_handler, LOCK_EX);
            }

            if ($result) {
                $this->_lock_acquired_time = microtime(true);
                // @todo Не работает под Windows
                fwrite($this->_file_handler, posix_getpid()."\n".microtime(true)."\n".posix_getuid()."\n\n");
                fflush($this->_file_handler);
                $this->_lock_acquired = true;
            } else {
                fclose($this->_file_handler);
                $this->_file_handler = false;
            }

            return $result;
        }

        function release_lock() {
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
        function get_acquired_time() {
            return $this->_lock_acquired_time;
        }

        /**
         * @return string
         */
        function get_mutex_name() {
            return $this->_mutex_name;
        }

        /**
         * @return boolean
         */
        function get_delete_on_release() {
            return $this->_delete_on_release;
        }

        /**
         * @return boolean
         */
        function is_acquired() {
            return $this->_lock_acquired;
        }
    }

?>