<?php

namespace Cache;

interface Cache {
	public function Get( $key , $callback = null );
	public function Set( $key , $value );
	public function Lock( $key, $callback );
}
interface CacheOne {
	public function Get($callback = null);
	public function Set($value, $timeAlive = false);
	public function IsEmpty();
}

class FileReaderMini {
	private $handle;
	public function __construct($handle) {
		$this->handle = $handle;
	}
	private $isLockProcess = false;
	public function Lock($callback) {
		if ( $this->isLockProcess ) { throw new \Exception("File error: lock cycle call"); }
		$errorRise = false;
		$isLock = false;
		try {
			if ( !@flock($this->handle, LOCK_EX) ) { throw new \Exception("File error: flock"); }
			$isLock = true;
			$this->isLockProcess = true;			
			$callback($this);
		} catch(\Exception $errorRise) {
		}
		$this->isLockProcess = false;
		if ( $isLock ) {
			@flock($this->handle, LOCK_UN);
		}
		if ( $errorRise ) {
			throw $errorRise;
		}
		return $this;
	}
	public function Seek( $offset = 0 ) {
		if ( 0 !== @fseek($this->handle, $offset) ) { throw new \Exception("File error: fseek"); }
		return $this;
	}
	public function GetSize() {
		$stat = @fstat($this->handle);
		if ( !$stat || !isset($stat['size']) ) { throw new \Exception("File error: fstat"); }
		return $stat['size'];
	}
	public function Truncate( $size ) {
		if ( false === @ftruncate($this->handle, $size) ) { throw new \Exception("File error: ftruncate"); }
		return $this;
	}
	public function Read($offset, $size) {
		$this->Seek($offset);
		$data = fread($this->handle, $this->GetSize());
		return $data;
	}
	public function Write($offset, $buffer) {
		$this->Seek($offset);
		if ( @fwrite($this->handle, $buffer) !== strlen($buffer) ) { throw new \Exception("File error: fwrite"); }
		return $this;
	}
}
class FileCacheReader implements CacheOne {
	private $reader;
	public $timeCreate = false;
	public $timeAlive = false;
	public $isEmpty = true;
	private function GetInfo() {
		if ( $this->reader->GetSize() >= 8 ) {
			$info = $this->reader->Read(0, 8);
			$this->timeCreate = unpack("l", substr($info,0,4))[1];
			$this->timeAlive  = (~0 === $this->timeAlive = unpack("l", substr($info,4,4))[1]) ? false : $this->timeAlive;
			$this->isEmpty = !( $this->timeAlive === false || $this->timeCreate + $this->timeAlive > time() );
		} else {
			$this->timeCreate = false;
			$this->timeAlive = false;
			$this->isEmpty = true;
		}
		return $this;
	}
	private function SetInfo( $timeAlive = false ) {
		$this->timeCreate = time();
		$this->timeAlive = $timeAlive;
		$this->isEmpty = false;
		$this->reader->Write(0, pack("l", $this->timeCreate) . pack("l", $this->timeAlive === false ? ~0 : $this->timeAlive));
		return $this;
	}
	
	public function __construct($reader) {
		$this->reader = $reader;
		$this->GetInfo();
	}
	public function Get( $callback = null ) {
		if ( $this->isEmpty ) {
			if ( $callback === null ) {
				return null;
			}
			$this->Set( $data = $callback() );
			return $data;
		}
		return @unserialize( $this->reader->Read(8, $this->reader->GetSize() - 8) );
	}
	public function Set($value, $timeAlive = false) {
		$data = serialize($value);
		$this->reader->Truncate(8+strlen($data))->Write(8, $data);
		$this->SetInfo( $timeAlive );
		return $this;
	}
	public function IsEmpty() {
		return $this->isEmpty;
	}
}
class FileCache implements Cache {
	private $dirPrefix;
	private $dirName = "file-cache/";
	private $sectionBin;
	public function __construct( $section = null, $dir = null ) {
		if ( $dir === null ) { $dir = __DIR__; }
		
		$this->dirPrefix = $dir . "/";
		
		$this->sectionBin = hex2bin( md5($section) );
	}
	
	public function OpenFile($callback) {
		if ( false === $handle = @fopen($this->absPath, "c+") ) { throw new \Exception("File error: fopen(\"{$this->absPath}\")"); }
		$reader = new FileReaderMini($handle);

		$eRise = false;
		try {
			$reader->Seek(0);
			$callback( $reader );
		} catch(\Exception $eRise) {
		}

		@fclose( $handle );
		if  ( $eRise ) { throw $eRise; }		
	}

	public $isEmpty = true;
	public function Get( $key, $callback = null ) {
		$this->isEmpty = true;
		$this->doPath( $key , 0 );
		if ( !file_exists($this->absPath) && $callback === null ) {
			return null;
		}
		$result = null;
		$this->Lock($key, function($fr) use(&$result, $callback) {
			$result = $fr->Get($callback);
			$this->isEmpty = $fr->IsEmpty();
		});
		return $result;
	}
	public function Set( $key , $value , $timeAlive = false ) {
		$this->Lock($key, function($fr) use($value, $timeAlive) {
			$fr->Set($value, $timeAlive);
		});
		return $this;
	}
	
	public function Lock( $key, $callback ) {
		$this->doPath( $key , 1 );
		$this->OpenFile(function($reader) use($callback) {
			$reader->Lock(function($reader) use($callback) {
				$callback( new FileCacheReader($reader) );
			});
		});
		return $this;
	}

	private $key;
	private $hash;
	private $relDir;
	private $relPath;
	private $absDir;
	private $absPath;
	private function doPath( $key , $mk = false ) {
		$hash = bin2hex( hex2bin( md5( $key ) ) ^ $this->sectionBin );
		
		$relDir = $this->dirName . substr($hash, 0, 2) . "/" . substr($hash, 2, 2) . "/";
		$relPath = $relDir . substr($hash, 4);
		$this->key = $key;
		$this->hash = $hash;
		$this->relDir = $relDir;
		$this->relPath = $relPath;
		$this->absDir = $this->dirPrefix . $relDir;
		$this->absPath = $this->dirPrefix . $relPath;

		if ( $mk && !is_dir($this->absDir) && !@mkdir($this->absDir, 0777, 1) ) { throw new \Exception("File error: mkdir(\"{$this->absDir}\")"); }
	}
}