<?php
namespace Magezon\Core\Helper;

class InputStream
{
    public function setInputStream($content)
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $content);
        rewind($stream);
        // Override php://input temporarily
        stream_wrapper_restore('php');
        stream_wrapper_register('php', get_class($this));
        $this->stream = $stream;
    }

    public function stream_open($path, $mode, $options, &$opened_path)
    {
        if ($path === 'php://input') {
            return true;
        }
        return false;
    }

    public function stream_read($count)
    {
        return fread($this->stream, $count);
    }

    public function stream_eof()
    {
        return feof($this->stream);
    }

    public function stream_close()
    {
        fclose($this->stream);
    }
}