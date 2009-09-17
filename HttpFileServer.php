<?php
/**
 * HttpFileServer
 *
 * Copyright (c) 2009, Krzysztof Kotowicz <kkotowicz at gmail dot com>.
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions
 * are met:
 *
 *   * Redistributions of source code must retain the above copyright
 *     notice, this list of conditions and the following disclaimer.
 *
 *   * Redistributions in binary form must reproduce the above copyright
 *     notice, this list of conditions and the following disclaimer in
 *     the documentation and/or other materials provided with the
 *     distribution.
 *
 *   * Neither the name of Krzysztof Kotowicz nor the names of his
 *     contributors may be used to endorse or promote products derived
 *     from this software without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS
 * FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 * COPYRIGHT OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT,
 * INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING,
 * BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
 * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
 * CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT
 * LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN
 * ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 *
 * @copyright  2009 Krzysztof Kotowicz <kkotowicz at gmail dot com>
 * @license    http://www.opensource.org/licenses/bsd-license.php  BSD License
 * @version    $Id$
 * @author     Krzysztof Kotowicz <kkotowicz at gmail dot com>
 */

/**
 * HTTPFileServer class
 * Simple class allowing you to store and retrieve files on a HTTP server, using HTTP methods PUT/GET to handle file storage.
 * The server uses HTTP response codes to report errors/success
 *
 * Example:
 * <code>
 * require 'HttpFileServer.php';
 *
 * $options = array(
 *  'storage' => '/absolute/path/to/file/storage', // without trailing slash. needs writable access
 * );
 *
 * //determine filename to store/fetch
 * $filename = !empty($_SERVER['PATH_INFO']) ? $_SERVER['PATH_INFO'] : (!empty($_GET['url']) ? $_GET['url'] : "/file");
 *
 * try {
 *     $server = new HttpFileServer($options);
 *     $server->setFilename($filename);
 *     $server->handle();
 * } catch (HttpFileServerException $e) {
 *     $e->renderHTTP();
 * }
 * </code>
 *
 * You may now store files using any HTTP client e.g. wget:
 * <code>
 * # store file using POST
 * wget --post-file=file_to_send.txt http://server/index.php/path/to/store/file.txt
 * # retrieve file using GET
 * wget http://server/index.php/path/to/store/file.txt
 * </code>
 */
class HttpFileServer {

    /**
     * @var array $options
     */
    protected $options = array();

    /**
     * @var string $filename absolute filename to operate on
     */
    protected $filename = null;

    /**
     * @var string $rel_filename filename relative to storage
     */
    protected $rel_filename = null;

    /**
     * @var array $dirs all directories that file resides in
     */
    protected $dirs = array();

    /**
     * Constructor
     * @param array $options
     * @throws HttpFileServerException
     */
    public function __construct(array $options = null) {
        $this->setOptions($options);
        if (!is_dir($this->getOption('storage'))) {
            throw new HttpFileServerException('Could not init server storage');
        }
    }

    /**
     * Sets object options.
     * @param array $options
     */
    public function setOptions(array $options) {
        foreach ($options as $option => $value)
            $this->setOption($option, $value);
    }

    /**
     * Sets object option
     * @param string $option
     * @param mixed $value
     */
    public function setOption($option, $value) {
        $this->options[$option] = $value;
    }

    /**
     * Returns object option value or null if option is undefined
     * @param string $option
     * @return mixed|null
     */
    public function getOption($option) {
        return array_key_exists($option, $this->options) ? $this->options[$option] : null;
    }

    /**
     * Handle request, dispatching it to POST/PUT/GET methods
     * @throws HttpFileServerException
     */
    public function handle() {
        $method = 'handle' . ucfirst($_SERVER['REQUEST_METHOD']);
        if (method_exists($this, $method))
            $this->{$method}();
        else
            throw new HttpFileServerException('Invalid HTTP method');
    }

    /**
     * Handle POST request - currently forwards to PUT
     */
    protected function handlePost() {
        return $this->handlePut();
    }

    /**
     * Handle PUT request - store a file in storage
     * @throws HttpFileServerException
     */
    protected function handlePut() {

        $rel_filename = $this->rel_filename;
        $filename = $this->filename;
        $temp = null;

        // create non existing directories for file
        if (!self::createDirs($this->dirs, $this->getOption('storage') . DIRECTORY_SEPARATOR)) {
          throw new HttpFileServerException('Could not create file', 501, "Could not create file " . $rel_filename);
        }

        // create temporary file
        $temp = tempnam(dirname($this->filename), 'temp');
        if (!$temp) {
            throw new HttpFileServerException("Could not create temporary file", 503);
        }

        try {
            // open streams
            $f = fopen($temp, "wb"); // temporary file
            $s = fopen("php://input", "r"); // POST raw data
            $ok = true;
            if (!$f || !$s) {
                throw new HttpFileServerException("Could not create file", 504, "Could not create file " . $rel_filename);
            }

            // copy streams
            while($kb = fread($s, 1024))
            {
                if (!fwrite($f, $kb, 1024)) {
                    throw new HttpFileServerException("Could not write to file", 505, "Could not write to file " . $rel_filename);
                }
            }

            fclose($f);
            fclose($s);

            // remove previous version if exists
            if (file_exists($filename) && !unlink($filename))
                throw new HttpFileServerException("Could not remove previous file", 506, "Could not remove prevoius version of " . $rel_filename);

            // rename temp to final file
            if (!rename($temp, $filename))
                throw new HttpFileServerException("Could not finish writing file", 507, "Could not finish writing file" . $rel_filename);

        } catch (HttpFileServerException $e) {
            // clean up
            if ($f) fclose($f);
            if ($s) fclose($s);
            if ($temp && file_exists($temp))
                  unlink($temp);

            throw $e; // rethrow
        }

        header("HTTP/1.1 201 Created"); // respond with correct HTTP header
        echo "File " . $rel_filename . " created";
    }

    /**
     * Handle GET requests - otuput a file to client
     * @throws HttpFileServerException
     */
    protected function handleGet() {
        $filename = $this->filename;

        if (!file_exists($filename) ||  !is_file($filename))
            throw new HttpFileServerException('Not found', 404, 'Could not find file ' . $this->rel_filename);

        header('Expires: 0');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Pragma: public');
        header('Content-Length: ' . filesize($filename));
        readfile($filename);
    }

    /**
     * Sets filename to operate on
     * Assures that the filename will be put within storage
     * @param string $filename filename path (relative to storage)
     * @throws HttpFileServerException
     */
    public function setFilename($filename) {
        $filename = self::getAbsolutePath($this->getOption('storage') . $filename);

        if (strpos($filename, $this->getOption('storage') . DIRECTORY_SEPARATOR) !== 0) {
            throw new HttpFileServerException('Forbidden request', 403);
        }

        $this->filename = $filename;

        $this->rel_filename = preg_replace('#^' . preg_quote($this->getOption('storage') . DIRECTORY_SEPARATOR, '#') . '#', '', $filename);

        $this->dirs = explode(DIRECTORY_SEPARATOR, $this->rel_filename);
        array_pop($this->dirs); // last part is a filename, leave it out
    }

    /**
     * Get absolute path from a given path, resolving all '.' and '..'
     * We don't use realpath() as the file might not exists yet
     * @param string $path relative path
     * @return string absolute path
     */
    protected static function getAbsolutePath($path) {
        $path = str_replace(array('/', '\\'), DIRECTORY_SEPARATOR, $path);
        $parts = array_filter(explode(DIRECTORY_SEPARATOR, $path), 'strlen');
        $absolutes = array();
        foreach ($parts as $part) {
            if ('.' == $part) continue;
            if ('..' == $part) {
                array_pop($absolutes);
            } else {
                $absolutes[] = $part;
            }
        }

        $path = implode(DIRECTORY_SEPARATOR, $absolutes);
        if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') { // we need to prepend this with "/" on unices
            $path = DIRECTORY_SEPARATOR . $path;
        }

        return $path;
    }

    /**
     * Create directories in filesystem
     * @param array $dirs directories to create (each following element is a subdirectory of previous one)
     * @param string $base_dir base directory we create all subdirectories in
     * @return bool were all directories created?
     */
    protected static function createDirs($dirs, $base_dir) {
      $dir_string = $base_dir;
      foreach ($dirs as $dir) {
        $dir_string .= $dir . DIRECTORY_SEPARATOR;
        if (!is_dir($dir_string) && !mkdir($dir_string))
            return false;
      }

      return true;
    }

}

/**
 * Exception for HTTP server
 * Allows rendering exception as HTTP header and HTML in body
 */
class HttpFileServerException extends Exception {

    protected $content = null;

    public function __construct($message = null, $code = 501, $content = null) {
        parent::__construct($message, $code);
        $this->content = $content;
    }

    public function renderHTTP() {
        header("HTTP/1.1 " . $this->code . ' ' . $this->getMessage());
        echo "<h1>" . htmlspecialchars($this->getMessage()) . "</h1>";
        echo $this->content;
    }
}
