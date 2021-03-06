<?php
	class App extends DB {

		/**
		 * init DB handler
		 */
		function __construct()
		{
			parent::__construct();
		}

		/**
		 * Available methods
		 */
		public static $allowed_methods = array(
			'saveCV',
			'deleteCV'
		);

		/**
		 * @var default response body
		 */
		public static $response = array(
			'data' => null,
			'success' => false
		);

		/**
		 * return all CVs in the DB
		 * @return [array]
		 */
		public static function getCVs( $cvID = false )
		{
			// return if id is specified
			if ( $cvID )
				return DB::query( 'SELECT cv.cvID, cv.dateline, p.personName, p.phone, p.location, p.birthdate, p.hobbies, p.institutions, p.companies FROM cvs cv JOIN persons p ON cv.personID = p.personID ORDER BY cvID DESC' )->fetch_assoc();

			// list array
			$list = array();

			// fetch CVs data
			$sql = DB::query( 'SELECT cv.cvID, cv.dateline, p.personName, p.phone, p.location, p.birthdate, p.hobbies, p.institutions, p.companies FROM cvs cv JOIN persons p ON cv.personID = p.personID ORDER BY cvID DESC' );
			while ( $item = $sql->fetch_assoc() )
				$list[] = self::prepareCV( $item );

			// return list
			return $list;
		}

		/**
		 * Preparing CV data for being shown
		 * @param  [array] $data [unprepared array data]
		 * @return [array]       [ready to output array]
		 */
		public static function prepareCV( $data )
		{

			$hobbies = json_decode( urldecode( $data[ 'hobbies' ] ) , true );
			$institutions = self::getFields ( 'institutions' , 'institutionID' , $data['institutions'] );
			$companies = self::getFields ( 'companies' , 'companyID' , $data['companies'] );

			$cv = array(
				'cvID' => $data['cvID'],
				'name' => urldecode( $data['personName'] ),
				'phone' => urldecode( $data[ 'phone' ] ),
				'location' => urldecode( $data[ 'location' ] ),
				'birthdate' => date( 'j M Y', $data[ 'birthdate' ]),
				'fields' => array(
					'dates' => array(
						'hobbies' => array(),
						'education' => array(),
						'work_experience' => array()
					),
					'hobbies' => ( count( $hobbies ) ) ? $hobbies : array( null, null ) ,
					'education' => ( count( $institutions ) ) ? array() : array( null, null ),
					'work_experience' => ( count( $companies ) ) ? array() : array( null, null )
				),
				'dateline' => date( 'j M Y, H:i', $data[ 'dateline' ] )
			);

			if (count( $institutions ))
				foreach ( $institutions as $institution )
				{
					$cv[ 'fields' ][ 'dates' ][ 'education' ][] = $institution[ 'period' ];
					$cv[ 'fields' ][ 'education' ][] = urldecode( $institution[ 'institutionName' ] );
				}

			if (count( $companies ))
				foreach ( $companies as $company )
				{
					$cv[ 'fields' ][ 'dates' ][ 'work_experience' ][] = array(
						'from' => date( 'M Y', $company[ 'from' ] ),
						'until' => date( 'M Y', $company[ 'until' ] )
					);

					$cv[ 'fields' ][ 'work_experience' ][] = urldecode( $company[ 'companyName' ] );
				}

			return $cv;

		}

		/**
		 * Get fields from list
		 */
		private static function getFields ( $table, $field, $list )
		{
			if ( !$list )
				return null;

			$result = array();

			foreach( explode( ',', $list ) as $value )
				$result[] = DB::query( "SELECT * FROM " . $table . " WHERE " . $field . " = ". (int) $value )->fetch_assoc();

			return $result;
		}

		/**
		 * read data from files of specified type
		 * @return [array]
		 */
		public static function getData( $folder )
		{
			// language var
			$data = array();

			// search for language files
			$files = array_diff(
				scandir ( sprintf( '%s/%s/', ROOT_PATH, $folder ) ),
				array ( '.', '..' )
			);

			// switch method of data reading due to different nature of files
			$type = ( $folder == 'lang' ) ? 'parse_ini_file' : 'file_get_contents';

			// read data from files
			foreach ( $files as $file )
				$data[ pathinfo( $file, PATHINFO_FILENAME ) ] = $type( sprintf('%s/%s/%s', ROOT_PATH, $folder, $file ) );

			return $data;
		}

		/**
		 * Deleting a CV entry from database
		 * @param  int $id CV ID
		 */
		public static function deleteCV ( $data )
		{

			// get personID before deleting
			$person = DB::query( 'SELECT c.personID, p.institutions, p.companies FROM cvs c JOIN persons p ON c.personID = p.personID WHERE cvID = '. (int) $data[ 'cvID'] . ' LIMIT 1' )->fetch_assoc();

			// delete all entries related to the cvID
			DB::query( "DELETE cvs, persons FROM cvs JOIN persons USING(personID) WHERE personID = " . $person[ 'personID' ] );
			DB::query( "DELETE FROM institutions WHERE institutionID IN (" . $person[ 'institutions' ] . ")" );
			DB::query( "DELETE FROM companies WHERE companyID IN (" . $person[ 'companies' ] . ")" );

			// change state
			self::$response = array(
				'data' => ( int ) $data[ 'cvID' ],
				'success' => true
			);

		}

		/**
		 * Validating and inserting CV into DB
		 * @param  array $data CV data
		 * @return mixed
		 */
		public static function saveCV ( $data )
		{
			// unwrap container
			$data = $data['data'];

			// validate birthdate
			if ( strtotime( $data['birthdate'] ) )
			{
				// validate number
				if ( ctype_digit( str_replace(' ', '', $data['phone'] ) ) )
				{

					// empty lists
					$institutions = $companies = array();

					// removing empty array elements
					$data['fields']['hobbies'] = array_filter( $data['fields']['hobbies'] );
					$data['fields']['education'] = array_filter( $data['fields']['education'] );
					$data['fields']['work_experience'] = array_filter( $data['fields']['work_experience'] );

					// debugging
					// print_r( $data );

					// insert institutions
					foreach ( $data['fields']['education'] as $key => $name )
					{
						DB::query("INSERT INTO institutions ( institutionName, period ) VALUES ( '".urlencode( $name )."', '". $data['fields']['dates']['education'][ $key ] ."' )");
						$institutions[] = DB::insertedID();
					}

					// insert companies
					foreach ( $data['fields']['work_experience'] as $key => $name )
					{

						// strings to timestamps
						$from = strtotime( $data['fields']['dates']['work_experience'][ $key ]['from'] );
						$until = strtotime( $data['fields']['dates']['work_experience'][ $key ]['until'] );

						if ( $from && $until )
						{
							DB::query("INSERT INTO companies ( companyName, `from`, until ) VALUES ( '". urldecode( $name )."', ". $from .", ". $until ." )");
							$companies[] = DB::insertedID();
						}

					}

					// inserting person
					$sql = DB::query( "INSERT INTO persons ( personName, phone, location, birthdate, hobbies, institutions, companies ) VALUES ( '". urlencode( $data['name'] ) ."', '".trim( $data['phone'] )."', '". urlencode( $data['location'] ) ."', ". strtotime( $data['birthdate'] ) .", '". urlencode( json_encode( $data['fields']['hobbies'] ) ) ."', '".implode( ',' , $institutions )."', '".implode( ',' , $companies )."' )" );

					// getting person ID
					$personID = DB::insertedID();

					// Insert CV entry
					DB::query( "INSERT INTO cvs ( personID, dateline ) VALUES ( ".$personID.", unix_timestamp() )" );

					// Prepare CV data
					$cv = self::getCVs( DB::insertedID() );

					// Set state to successful
					self::$response = array(
						'data' => self::prepareCV( $cv ),
						'success' => true
					);

				} else
					self::$response['data'] = 'invalid_phone';
			} else
				self::$response['data'] = 'invalid_birthdate';
		}

	}
?>