<?php
use xPaw\SourceQuery\Exception\InvalidPacketException;
use xPaw\SourceQuery\Exception\SocketException;

/**
	 * Class written by xPaw
	 *
	 * Website: http://xpaw.me
	 * GitHub: https://github.com/xPaw/PHP-Source-Query-Class
	 */
	
	class SourceQuerySocket
	{
		public $Socket;
		public $Engine;
		
		public $Ip;
		public $Port;
		public $Timeout;
		
		/**
		 * Points to buffer class
		 * 
		 * @var SourceQueryBuffer
		 */
		private $Buffer;
		
		/**
		 * @param SourceQueryBuffer $Buffer
		 */
		public function __construct( $Buffer )
		{
			$this->Buffer = $Buffer;
		}
		
		public function Close( )
		{
			if( $this->Socket )
			{
				FClose( $this->Socket );
				
				$this->Socket = null;
			}
		}
		
		/**
		 * @param string $Ip
		 * @param integer $Port
		 * @param integer $Timeout
		 * @param integer $Engine
		 */
		public function Open( $Ip, $Port, $Timeout, $Engine )
		{
			$this->Timeout = $Timeout;
			$this->Engine  = $Engine;
			$this->Port    = $Port;
			$this->Ip      = $Ip;
			
			$this->Socket = @FSockOpen( 'udp://' . $Ip, $Port, $ErrNo, $ErrStr, $Timeout );
			
			if( $ErrNo || $this->Socket === false )
			{
				throw new SocketException( 'Could not create socket: ' . $ErrStr, SocketException::COULD_NOT_CREATE_SOCKET);
			}
			
			Stream_Set_Timeout( $this->Socket, $Timeout );
			Stream_Set_Blocking( $this->Socket, true );
			
			return true;
		}
		
		public function Write( $Header, $String = '' )
		{
			$Command = Pack( 'ccccca*', 0xFF, 0xFF, 0xFF, 0xFF, $Header, $String );
			$Length  = StrLen( $Command );
			
			return $Length === FWrite( $this->Socket, $Command, $Length );
		}
		
		public function Read( $Length = 1400 )
		{
			$this->Buffer->Set( FRead( $this->Socket, $Length ) );
			
			if( $this->Buffer->Remaining( ) === 0 )
			{
				// TODO: Should we throw an exception here?
				return;
			}
			
			$Header = $this->Buffer->GetLong( );
			
			if ($Header === -1) // Single packet
			{
				 // We don't have to do anything
			}
			else if ($Header === -2) // Split packet
			{
				$Packets      = Array( );
				$IsCompressed = false;
				$ReadMore     = false;
				
				do
				{
					$RequestID = $this->Buffer->GetLong( );
					
					switch ($this->Engine)
					{
						case SourceQuery :: GOLDSOURCE:
						{
							$PacketCountAndNumber = $this->Buffer->GetByte( );
							$PacketCount          = $PacketCountAndNumber & 0xF;
							$PacketNumber         = $PacketCountAndNumber >> 4;
							
							break;
						}
						case SourceQuery :: SOURCE:
						{
							$IsCompressed         = ($RequestID & 0x80000000) !== 0;
							$PacketCount          = $this->Buffer->GetByte( );
							$PacketNumber         = $this->Buffer->GetByte( ) + 1;
							
							if ($IsCompressed)
							{
								$this->Buffer->GetLong( ); // Split size
								
								$PacketChecksum = $this->Buffer->GetUnsignedLong( );
							} else
							{
								$this->Buffer->GetShort( ); // Split size
							}
							
							break;
						}
					}
					
					$Packets[ $PacketNumber ] = $this->Buffer->Get( );
					
					$ReadMore = $PacketCount > sizeof( $Packets );
				}
				while( $ReadMore && $this->Sherlock( $Length ) );
				
				$Buffer = Implode( $Packets );
				
				// TODO: Test this
				if( $IsCompressed )
				{
					// Let's make sure this function exists, it's not included in PHP by default
					if( !Function_Exists( 'bzdecompress' ) )
					{
						throw new RuntimeException( 'Received compressed packet, PHP doesn\'t have Bzip2 library installed, can\'t decompress.' );
					}
					
					$Buffer = bzdecompress( $Buffer );
					
					if( CRC32( $Buffer ) !== $PacketChecksum )
					{
						throw new InvalidPacketException( 'CRC32 checksum mismatch of uncompressed packet data.', InvalidPacketException::CHECKSUM_MISMATCH);
					}
				}
				
				$this->Buffer->Set( SubStr( $Buffer, 4 ) );
			} else
			{
				throw new InvalidPacketException( 'Socket read: Raw packet header mismatch. (0x' . DecHex( $Header ) . ')', InvalidPacketException::PACKET_HEADER_MISMATCH);
			}
		}
		
		/**
		 * @param integer $Length
		 */
		private function Sherlock( $Length )
		{
			$Data = FRead( $this->Socket, $Length );
			
			if( StrLen( $Data ) < 4 )
			{
				return false;
			}
			
			$this->Buffer->Set( $Data );
			
			return $this->Buffer->GetLong( ) === -2;
		}
	}