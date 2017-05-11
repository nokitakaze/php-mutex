<?php

    namespace NokitaKaze\Mutex;

    interface MutexInterface {
        const DOMAIN = 0;// В пределах одного домена
        const DIRECTORY = 1;// В пределах одной папки DOCUMENT_ROOT
        const SERVER = 2;// All virtual hosts on server with one tmp-folder e.g. "/tmp"

        /**
         * MutexInterface constructor.
         *
         * @param MutexSettings|array $settings
         */
        function __construct($settings);

        /**
         * @param integer|double $time
         *
         * @return mixed
         */
        function get_lock($time = -1);

        function release_lock();

        /**
         * @return boolean
         */
        function is_free();

        /**
         * @return boolean
         */
        function is_acquired();
    }

?>