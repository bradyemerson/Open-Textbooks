<?php

require ('includes/autoloads.php');

date_default_timezone_set('GMT');

$first_start = microtime(true);

$user_token = time() . rand(); //globalled in url_functions

$json = array();
$json['data'] = array();

if (!$conn = connect()) {
	$json['status'] = 'Error: DB Connect failure';
} else if (!valid_ISBN13($_GET['isbn'])) {
	$json['status'] = 'Error: Invalid ISBN';
} else if (!valid_ID($_GET['campus'])) {
	$json['status'] = 'Error: Invalid campus id';
} else {
    $query = 'SELECT Storefront_URL FROM Bookstores
INNER JOIN Campuses ON Campuses.Bookstore_ID = Bookstores.Bookstore_ID
WHERE Campuses.Campus_ID = ' . mysql_real_escape_string($_GET['campus']);
    if (!$result = mysql_query($query)) {
		$json['status'] = 'Error: Campus SQL query based on campus=' . $_GET['campus'] .
			' yielded error: ' . mysql_error();
	} else if (mysql_num_rows($result) == 0) {
		$json['status'] = 'Error: Campus ID not found';
	} else {
        $response = mysql_fetch_assoc($result);
        $json['storefront_url'] = $response['Storefront_URL'];
    }
    
	$query = 'SELECT 
Items.Title, Items.Edition, Items.Authors, Items.Year, Items.Publisher,
Class_Items_Cache.New_Price, Class_Items_Cache.Used_Price,Class_Items_Cache.New_Rental_Price,Class_Items_Cache.Used_Rental_Price,
Classes_Cache.Class_ID, 
Classes_Cache.Cache_TimeStamp BETWEEN NOW() - INTERVAL 1 WEEK AND NOW() as `CacheValid` 
FROM Class_Items_Cache
INNER JOIN Items ON Items.Item_ID = Class_Items_Cache.Item_ID
INNER JOIN Classes_Cache ON Classes_Cache.Class_ID = Class_Items_Cache.Class_ID
INNER JOIN Courses_Cache ON Courses_Cache.Course_ID = Classes_Cache.Course_ID
INNER JOIN Departments_Cache ON Departments_Cache.Department_ID = Courses_Cache.Department_ID
INNER JOIN Divisions_Cache ON Divisions_Cache.Division_ID = Departments_Cache.Division_ID
INNER JOIN Terms_Cache ON Terms_Cache.Term_ID = Divisions_Cache.Term_ID
WHERE Items.ISBN = \'' . mysql_real_escape_string($_GET['isbn']) . '\' AND Terms_Cache.Campus_ID = ' .
		mysql_real_escape_string($_GET['campus']) . '
ORDER BY Class_Items_Cache.Cache_TimeStamp DESC
LIMIT 1';

	if (!isset($json['status']) && !$result = mysql_query($query)) {
		$json['status'] = 'Error: SQL query based on campus=' . $_GET['campus'] .
			' and isbn=' . $_GET['isbn'] . ' yielded error: ' . mysql_error();
	} else if (mysql_num_rows($result) == 0) {
		$json['status'] = 'Unable to find matching course';
	} else {
		$cacheResult = mysql_fetch_assoc($result);
		if ($cacheResult['CacheValid']) {
			$json['data'] = array(
                'isbn' => $_GET['isbn'],
                'title' => $cacheResult['Title'],
                'edition' => $cacheResult['Edition'],
                'authors' => $cacheResult['Authors'],
                'year' => $cacheResult['Year'],
                'publisher' => $cacheResult['Publisher'],
                'new_price' => $cacheResult['New_Price'],
				'used_price' => $cacheResult['Used_Price'],
                'new_rental_price' => $cacheResult['New_Rental_Price'],
				'used_rental_price' => $cacheResult['Used_Rental_Price'], 
                );
			$json['status'] = 'ok';
		} else {
			$query = class_items_query(array($cacheResult['Class_ID']), $_GET['campus']);

			if (!$result = mysql_query($query)) {
				$json['status'] = 'Error: Class-Items SQL query failed with ' .
					mysql_error();
			} else if (mysql_num_rows($result) == 0) {
				$json['status'] = 'Error: Class-Items SQL query yielded no results';
			} else {
				$row = mysql_fetch_assoc($result);
				if ($row['no_class_item'])
					//note that this is Class_Items_Cache.Class_ID, NOT Classes_Cache.Class_ID which must have been set from before (or else you'd get the 0 rows error above)
					{
					update_class_items_from_bookstore(array($row)); //this function updates the DB with the class-item data
					if (!$result = mysql_query($query)) {
						$json['status'] = 'Error: Class-Items SQL query failed with ' .
							mysql_error();
					} else
						if (mysql_num_rows($result) == 0) {
							$json['status'] = 'Error: Class-Items SQL query yielded no results';
						}
				} else {
					mysql_data_seek($result, 0); //rewind
				}

				if (mysql_num_rows($result)) //everything has worked..
					{
					$json['status'] = 'ok';

					$row = mysql_fetch_assoc($result);

					if ($row['Item_ID']) //it has at least one item
						{
						//So, loop the books into the array
						while ($row = mysql_fetch_assoc($result)) {
							$Books = load_books_from_row($Books, $row);
						}

						mysql_data_seek($result, 0);

						while ($row = mysql_fetch_assoc($result)) {
							if ($row['ISBN'] == $_GET['isbn']) {
								$json['data'] = array(
                                    'isbn' => $_GET['isbn'],
                                    'title' => $row['Title'],
                                    'edition' => $row['Edition'],
                                    'authors' => $row['Authors'],
                                    'year' => $row['Year'],
                                    'publisher' => $row['Publisher'],
                                    'new_price' => $row['New_Price'], 
                                    'used_price' => $row['Used_Price'], 
                                    'new_rental_price' => $row['New_Rental_Price'],
									'used_rental_price' => $row['Used_Rental_Price']
                                );
								break;
							}
						}
					}
				}
			}
		}
	}
}


//$json['storefront_url'] = 

$json['total_time'] = round(microtime(true) - $first_start, 3) * 1000;

header("Content-type: application/json");
echo json_encode($json);
