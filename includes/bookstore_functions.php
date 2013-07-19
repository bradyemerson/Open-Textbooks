<?php

//Bookstore functions: functions for querying bookstores.

function format_item($item) { //returns an item result with the proper formatting
    $format_arr = array('Necessity', 'Title', 'Edition', 'Authors', 'Publisher');

    foreach ($format_arr as $name) {
        if (isset($item[$name]) && $item[$name]) {
            //$item[$name] = ucwords(strtolower(trim($item[$name], " \t\n\r\0\x0B\xA0")));
            // i want to leave caps as they are, i will deal with it on the presentation side
            $item[$name] = trim($item[$name], " \t\n\r\0\x0B\xA0");
        }
    }
    if (isset($item['Year']) && $item['Year']) {
        $item['Year'] = date('Y', strtotime(trim($item['Year'])));
    }
    if (isset($item['ISBN']) && $item['ISBN']) {
        $item['ISBN'] = get_ISBN13(str_replace('&nbsp;', '', trim($item['ISBN'])));
    }
    if (isset($item['Bookstore_Price']) && $item['Bookstore_Price']) {
        $item['Bookstore_Price'] = priceFormat($item['Bookstore_Price']);
    }

    if (isset($item['New_Price']) && $item['New_Price']) {
        $item['New_Price'] = priceFormat($item['New_Price']);
    }
    if (isset($item['Used_Price']) && $item['Used_Price']) {
        $item['Used_Price'] = priceFormat($item['Used_Price']);
    }
    if (isset($item['New_Rental_Price']) && $item['New_Rental_Price']) {
        $item['New_Rental_Price'] = priceFormat($item['New_Rental_Price']);
    }
    if (isset($item['Used_Rental_Price']) && $item['Used_Rental_Price']) {
        $item['Used_Rental_Price'] = priceFormat($item['Used_Rental_Price']);
    }

    return $item;
}

function format_dropdown($dropdown) { //takes a dropdown array include name and value.  also instructor sometimes in the case of class.
    //ucwords term_name and class_code
    $title_caps = array('Term_Name', 'Class_Code');

    foreach ($dropdown as $name => $val) {
        if (is_array($val)) { //so we can get sections, or really anything, recursively.
            $dropdown[$name] = format_dropdown($val);
        } else {
            $dropdown[$name] = trim($val); //trim everything

            if (in_array($name, $title_caps)) {
                $dropown[$name] = ucwords($val);
            }
        }
    }

    return $dropdown;
}

function get_classes_and_items_from_neebo($valuesArr) {
    if (isset($valuesArr['Term_ID']) && !isset($valuesArr['Division_ID'])) {
        return array(); //because Neebo can't have divisions.
    }

    //store fetch_url and neebo extension. assume we it as store_value
    $doc = new DOMDocument();
    $returnArray = array();

    // defaults
    $options = array(
        CURLOPT_URL => $valuesArr['Fetch_URL'] . '/Course/GetCampusTerms',
        CURLOPT_REFERER => $valuesArr['Fetch_URL'] . '/Course',
        CURLOPT_POST => true,
        CURLOPT_USERAGENT => random_user_agent() // set a random agent now and then use it throughout
    );

    if (!isset($valuesArr['Term_ID'])) {
        //initialize the session
        $response = curl_request(array(CURLOPT_URL => $valuesArr['Storefront_URL']));
        if (!$response) {
            throw new Exception('Unable to initlalize Neebo session with values ' . print_r($valuesArr, true));
        }

        $options[CURLOPT_URL] = $valuesArr['Fetch_URL'] . '/Course/GetCampusTerms';
        $options[CURLOPT_POSTFIELDS] = array(
            'schoolId' => $valuesArr['Neebo_School_ID']
        );

        $response = curl_request($options);

        if (!$response) {
            throw new Exception('Unable to get campus terms with values ' . print_r($valuesArr, true));
        }

        @$doc->loadHTML($response); //because their HTML is imperfect
        $a_tags = $doc->getElementsByTagName('a');

        for ($i = 0; $i < $a_tags->length; $i++) {
            $a = $a_tags->item($i);
            preg_match("/^termSelected\('([a-z0-9-]{36})'\);$/", $a->getAttribute('onclick'), $matches);

            if (count($matches) == 2) {
                $returnArray[] = array('Term_Value' => $matches[1], 'Term_Name' => $a->nodeValue);
            }
        }
    } else if (!isset($valuesArr['Department_ID'])) { //get depts
        $options[CURLOPT_URL] = $valuesArr['Fetch_URL'] . '/Course/GetDepartments?termId=' . urlencode($valuesArr['Term_Value']);
        $response = curl_request($options);

        if (!$response) {
            throw new Exception('Unable to get departments with values ' . print_r($valuesArr, true));
        }

        @$doc->loadHTML($response); //imperfect html
        $finder = new DomXPath($doc);
        $as = $finder->query('//ul[@class="dept-list filtered"]//a');

        if ($as->length != 0) {
            for ($i = 0; $i < $as->length; $i++) { //loop through lis
                $a = $as->item($i);

                $dept_td = $finder->query('..//td', $a)->item(0);

                $returnArray[] = array('Department_Code' => $dept_td->nodeValue, 'Department_Value' => $a->getAttribute('id'));
            }
        } else {
            throw new Exception('Missing department ul response with values ' . print_r($valuesArr, true));
        }
    } else if (!isset($valuesArr['Course_ID'])) { //get courses AND sections.  this is different on neebo than on other systems.
        $options[CURLOPT_URL] = $valuesArr['Fetch_URL'] . '/Course/GetCourses?departmentId=' . urlencode($valuesArr['Department_Value']);
        $response = curl_request($options);

        $doc->loadHTML($response);
        $finder = new DomXPath($doc);
        $lis = $finder->query('//ul[@class="course-list filtered"]/li');
        if ($lis->length != 0) {
            for ($i = 0; $i < $lis->length; $i++) {
                $li = $lis->item($i);
                $course_code = $li->childNodes->item(1)->nodeValue;
                $course_value = null;
                $section_arr = array();

                $section_as = $finder->query('.//ul/li/a', $li);
                if ($section_as->length) {
                    for ($j = 0; $j < $section_as->length; $j++) {
                        $section_a = $section_as->item($j);
                        $section_value = $section_a->getAttribute('id');
                        $section_code = $section_a->nodeValue;

                        $section = array('Class_Value' => $section_value, 'Class_Code' => $section_code);

                        $section_arr[] = $section;
                    }
                }

                $returnArray[] = array('Course_Code' => $course_code, 'Course_Value' => $course_value, 'Classes' => $section_arr);
            }
        } else {
            throw new Exception('Missing course ul response with values ' . print_r($valuesArr, true));
        }
    } else if (!isset($valuesArr['Class_ID'])) {
        //sections should always be returned as part of the courses stuff.
        $returnArray = array();
    } else {
        //get textbooks..
        $url = $base . 'CourseMaterials/AddSection?sectionId=' . urlencode($valuesArr['Class_Value']);

        $result_url = $base . 'Course/Results';

        curl_request(array(CURLOPT_URL => $url)); //step 1, add section

        $response = curl_request(array(CURLOPT_URL => $result_url)); //step 2, get the response

        $returnArray['Class_ID'] = $valuesArr['Class_ID'];
        $returnArray['items'] = array();

        @$doc->loadHTML($response); //imperfect HTML
        $finder = new DomXPath($doc);

        $tds = $finder->query('//td[@class="course-materials-description"]');

        $items = array();

        $i = 0;

        if ($tds->length != 0) {
            foreach ($tds as $td) {

                $items[$i]['Title'] = $finder->query('.//p[@class="title"]', $td)->item(0)->nodeValue;

                $info = innerHTML($finder->query('.//p[@class="info"]', $td)->item(0)); //have to use innerHTML to keep the tags for parsing

                $info = explode('<br>', $info);

                //GET Authors, Edition, ISBN

                foreach ($info as $subject) {
                    $subject = explode(':', $subject);

                    $key = str_replace('<strong>', '', $subject[0]);

                    $value = str_replace('</strong>', '', $subject[1]);

                    if ($key == 'Edition') { //we need to get Edition and Publisher here..
                        $value = explode(',', $value);
                        $items[$i]['Edition'] = $value[0];
                        $items[$i]['Year'] = preg_replace('([^0-9]*)', '', $value[1]);
                    } else {
                        if (trim($key) == 'Author') {
                            $key = 'Authors';
                        }

                        $items[$i][$key] = $value;
                    }
                }

                $items[$i]['Necessity'] = $finder->query('.//a[@rel="/Help/RequirementHelp"]//strong', $td)->item(0)->nodeValue;

                //Get the max price
                $prices = $finder->query('.//td[@class="course-product-price"]/label');
                $priceList = array();
                foreach ($prices as $price) {
                    $priceList[] = preg_replace('([^0-9\.]*)', '', $price->nodeValue); //remove numbers so we can do max
                }

                $items[$i]['Bookstore_Price'] = max($priceList);

                $i++;
            }
            $returnArray['items'] = $items;
        }
    }

    return $returnArray;
}

function get_classes_and_items_from_textbooktech(array $valuesArr) {
    if (isset($valuesArr['Term_ID']) && !isset($valuesArr['Division_ID'])) {
        return array(); //because Textbook Tech doesn't have Division values.
    }

    $url = $valuesArr['Fetch_URL'] . 'checkout/onepage/';

    $referer = $valuesArr['Storefront_URL'] . 'textbook/';

    if (!isset($valuesArr['Class_ID'])) {
        $post = array();
        //prepare appropriate dropdown query depending on what they're trying to get...
        if (!isset($valuesArr['Term_ID'])) {
            $url .= 'availableTerms/';
            $post = array(
                'schoolId' => $valuesArr['Campus_Value']
            );
        } else if (!isset($valuesArr['Department_ID'])) {
            $url .= 'availableDepartments/';
            $post = array(
                'schoolId' => $valuesArr['Campus_Value'],
                'termNameId' => $valuesArr['Term_Value']
            );
        } else if (!isset($valuesArr['Course_ID'])) {
            $url .= 'availableCourses/';
            $post = array(
                'departmentId' => $valuesArr['Department_Value'],
                'termNameId' => $valuesArr['Term_Value']
            );
        } else if (!isset($valuesArr['Class_ID'])) {
            $url .= 'availableSections/';
            $post = array(
                'courseCode' => $valuesArr['Course_Value'],
                'departmentId' => $valuesArr['Department_Value'],
                'termNameId' => $valuesArr['Term_Value']
            );
        }

        $options = array(
            CURLOPT_URL => $url,
            CURLOPT_REFERER => $referer,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $post
        );
    } else { //prepare the class-items query
        $options = array(
            CURLOPT_URL => $valuesArr['Fetch_URL'] . 'catalog/product/view/id/' . $valuesArr['Class_Value'],
            CURLOPT_REFERER => $referer,
            CURLOPT_POST => true
        );
    }

    $response = curl_request($options);

    if (!$response) {
        throw new Exception('Failed to get a response with values ' . print_r($valuesArr, true));
    } else {
        $returnArray = array();

        if (!isset($valuesArr['Term_ID'])) {
            $json = json_decode($response);
            if (!$json) {
                throw new Exception('Failed to get term dropdown with values ' . print_r($valuesArr, true));
            }
            foreach ($json as $item) {
                if ($item->active == '1') {
                    $returnArray[] = array('Term_Value' => $item->id, 'Term_Name' => $item->term_name);
                }
            }
        } else if (!isset($valuesArr['Department_ID'])) {
            $json = json_decode($response);
            if (!$json) {
                throw new Exception('Failed to get department dropdown with values ' . print_r($valuesArr, true));
            }
            foreach ($json as $item) {
                $returnArray[] = array('Department_Value' => $item->id, 'Department_Code' => $item->department_code);
            }
        } else if (!isset($valuesArr['Course_ID'])) {
            $json = json_decode($response);
            if (!$json) {
                throw new Exception('Failed to get course dropdown with values ' . print_r($valuesArr, true));
            }
            foreach ($json as $item) {
                $returnArray[] = array('Course_Value' => $item->course_code, 'Course_Code' => $item->course_code);
            }
        } else if (!isset($valuesArr['Class_ID'])) {
            $json = json_decode($response);
            if (!$json) {
                throw new Exception('Failed to get class dropdown with values ' . print_r($valuesArr, true));
            }
            foreach ($json as $item) {
                $returnArray[] = array('Class_Value' => $item->magento_course_id, 'Class_Code' => $item->section_code);
            }
        } else { //continue with class items search
            $items = array();
            $html = str_get_html($response);

            $books = $html->find('.book-info');
            foreach ($books as $book) {
                $item = array();

                // first get the description info
                $name_em = $book->find('.book-name', 0);
                $span_pos = strpos($name_em->innertext, '(<span');
                $item['Title'] = html_entity_decode(substr($name_em->innertext, 0, $span_pos === false ? strlen($name_em->innertext) : $span_pos));
                if (stripos($name_em->innertext, 'REQUIRED') !== false) {
                    $item['Necessity'] = 'REQUIRED';
                } else {
                    $item['Necessity'] = 'OPTIONAL';
                }

                foreach ($book->find('.book-attr ul li') as $li) {
                    $value = trim(substr($li->innertext, strpos($li->innertext, '</b>') + 4));
                    if (!$value) {
                        continue;
                    }
                    switch (trim($li->find('b', 0)->innertext)) {
                        case 'Author:':
                            $item['Authors'] = $value;
                            break;
                        case 'Edition:':
                            $item['Edition'] = $value;
                            break;
                        case 'Publisher:':
                            $item['Publisher'] = $value;
                            break;
                        case 'ISBN:':
                            $item['ISBN'] = $value;
                            break;
                    }
                }

                // now pricing
                foreach ($book->find('.book-price') as $book_price) {
                    if (!trim($book_price->innertext)) {
                        continue;
                    }
                    $new_used = $book_price->find('input[type=radio]', 0)->getAttribute('class');
                    $price = $book_price->find('.price', 0)->plaintext;
                    switch ($new_used) {
                        case 'Rental':
                            $item['Used_Rental_Price'] = $price;
                            break;
                        case 'Used':
                            $item['Used_Price'] = $price;
                            if (!isset($item['Bookstore_Price'])) {
                                $item['Bookstore_Price'] = $price;
                            }
                            break;
                        case 'New':
                            $item['New_Price'] = $price;
                            $item['Bookstore_Price'] = $price;
                            break;
                    }
                }

                $items[] = $item;
            }

            $html->clear();
            unset($html);

            $returnArray['Class_ID'] = $valuesArr['Class_ID'];
            $returnArray['items'] = $items; //trim them all
        }

        return $returnArray;
    }
}

function get_classes_and_items_from_follett(array $valuesArr) {
    //We hardcode Program_Value and Campus_Value in the Campuses table because they are campus-specific and pretty much static.  Division_Value varies and is sometimes like a higher level department, so we give it it's own table..

    $returnArray = array();
    $url = $valuesArr['Fetch_URL'] . 'webapp/wcs/stores/servlet/';
    $referer = $valuesArr['Storefront_URL'];

    if ($valuesArr['Follett_HEOA_Store_Value'] == '1076') {
        if (isset($valuesArr['Division_ID'])) {
            // Ivy Tech does this backwards so we'll fix it
            $valuesArr['Division_Value'] = $valuesArr['Campus_Value'];
            $valuesArr['Campus_Value'] = '';
        } else if (isset($valuesArr['Term_ID'])) {
            return array();
        }
    }

    //We need to set these to empty or spaces appropraitely, because Follet expects them even when they aren't existent.
    if (!$valuesArr['Campus_Value']) {
        //Do all Follett schools have Campus_Values?  Answer: No, some do not.  And in fact, when they don't have it it's not even sent as a parameter.  To simplify things we just set it as an empty string for those cases..
        $valuesArr['Campus_Value'] = '';
    }
    if (isset($valuesArr['Division_ID']) && !$valuesArr['Division_Value']) {
        //We set it to " " (a space) when it's not there, cus it needs to be sent.
        $valuesArr['Division_Value'] = ' ';
    }

    //Note: Follett schools *always* have a Program_Value.  When they don't have a real world one, the store adds one with the display name "ALL".
    //Initial request to start the session with follett if we haven't already.. Follett won't let you do anything w/o one...
    if (!isset($valuesArr['Class_ID'])) { //note that we only need to do this for the dropdowns, not for booklook, which is on a seperate HEOA page which doesn't require a session.
        $options = array(
            CURLOPT_URL => $valuesArr['Storefront_URL'],
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 6.1; rv:21.0) Gecko/20100101 Firefox/21.0',
            CURLOPT_HTTPHEADER => array(
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.5',
            ),
        );

        $response = curl_request($options); //query the main page to pick up the cookies
        if (!$response) {
            throw new Exception('Unable to fetch Follett Storefront for session with values ' . print_r($valuesArr, true));
        }
    }

    //Prepare for the request and its handling depending on whats up next..
    if (!isset($valuesArr['Term_ID'])) {
        $url .= 'LocateCourseMaterialsServlet?demoKey=d&programId=' . urlencode($valuesArr['Program_Value']) . '&requestType=TERMS&storeId=' . urlencode($valuesArr['Store_Value']);

        $response_name = 'TERMS';
        $display_name = 'Term_Name';
        $value_name = 'Term_Value';
    } else if (!isset($valuesArr['Division_ID'])) {
        //The divisions request is always sent, even when there aren't any.
        $url .= 'LocateCourseMaterialsServlet?requestType=DIVISIONS&storeId=' . urlencode($valuesArr['Store_Value']) .
                '&campusId=' . urlencode($valuesArr['Campus_Value']) .
                '&demoKey=d&programId=' . urlencode($valuesArr['Program_Value']) .
                '&termId=' . $valuesArr['Term_Value'];

        $response_name = 'DIVISIONS';
        $display_name = 'Division_Name';
        $value_name = 'Division_Value';
    } else if (!isset($valuesArr['Department_ID'])) {
        $url .= 'LocateCourseMaterialsServlet?demoKey=d&divisionName=' . urlencode($valuesArr['Division_Value']) .
                '&campusId=' . urlencode($valuesArr['Campus_Value']) .
                '&programId=' . urlencode($valuesArr['Program_Value']) .
                '&requestType=DEPARTMENTS&storeId=' . urlencode($valuesArr['Store_Value']) .
                '&termId=' . urlencode($valuesArr['Term_Value']);

        $response_name = 'DEPARTMENTS';
        $display_name = 'Department_Code';
        $value_name = 'Department_Value';
    } else if (!isset($valuesArr['Course_ID'])) {
        $url .= 'LocateCourseMaterialsServlet?demoKey=d&divisionName=' . urlencode($valuesArr['Division_Value']) .
                '&campusId=' . urlencode($valuesArr['Campus_Value']) .
                '&programId=' . urlencode($valuesArr['Program_Value']) .
                '&requestType=COURSES&storeId=' . urlencode($valuesArr['Store_Value']) .
                '&termId=' . urlencode($valuesArr['Term_Value']) .
                '&departmentName=' . urlencode($valuesArr['Department_Code']) .
                '&_=';

        $response_name = 'COURSES';
        $display_name = 'Course_Code';
        $value_name = 'Course_Value';
    } else if (!isset($valuesArr['Class_ID'])) {
        $url .= 'LocateCourseMaterialsServlet?requestType=SECTIONS' .
                '&storeId=' . rawurlencode($valuesArr['Store_Value']) .
                '&demoKey=d' .
                ($valuesArr['Campus_Value'] ? '&campusId=' . rawurlencode($valuesArr['Campus_Value']) : '') .
                '&programId=' . rawurlencode($valuesArr['Program_Value']) .
                '&termId=' . rawurlencode($valuesArr['Term_Value']) .
                '&divisionName=' . rawurlencode($valuesArr['Division_Value']) .
                '&departmentName=' . rawurlencode($valuesArr['Department_Code']) .
                '&courseName=' . rawurlencode($valuesArr['Course_Code']);

        $response_name = 'SECTIONS';
        $display_name = 'Class_Code';
        $value_name = 'Class_Value';
    } else {
        //class books query.. it's special.
        $url .= 'booklookServlet?bookstore_id-1=' . urlencode($valuesArr['Follett_HEOA_Store_Value']) .
                '&term_id-1=' . urlencode($valuesArr['Follett_HEOA_Term_Value']) .
                '&div-1=' . urlencode($valuesArr['Division_Value']) .
                '&dept-1=' . urlencode($valuesArr['Department_Value']) .
                '&course-1=' . urlencode($valuesArr['Course_Value']) .
                '&section-1=' . urlencode($valuesArr['Class_Value']);
    }

    if (!isset($valuesArr['Class_ID'])) {
        // dropdown request
        $response = curl_request(array(
            CURLOPT_URL => $url,
            CURLOPT_REFERER => $referer,
            CURLOPT_USERAGENT => $options[CURLOPT_USERAGENT],
            CURLOPT_HTTPHEADER => array(
                'Accept: text/javascript, text/html, application/xml, text/xml, */*',
                'Accept-Language: en-US,en;q=0.5',
                'X-Requested-With: XMLHttpRequest',
                'X-Prototype-Version: 1.5.0'
            )
        ));
    } else {
        // class books query
        $response = curl_request(array(
            CURLOPT_URL => $url,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 6.1; rv:21.0) Gecko/20100101 Firefox/21.0'
        ));
    }

    if ($response) {
        $doc = new DOMDocument();
        @$doc->loadHTML($response); //because their HTML is imperfect

        if (!isset($valuesArr['Class_ID'])) { //dropdown response..
            //example $response: <script>parent.doneLoaded('{"meta":[{"request":"TERMS","skip":"false","campusActive":"true","progActive":"true","termActive":"true","size":"3"}],"data":[{"FALL 2011":"100019766","WINTER 2011-2012":"100021395","SPRING 2012":"100021394"}]}')</script>
            $script = $doc->getElementsByTagName('script');

            if ($script->length != 0) {
                $script = $script->item(0)->nodeValue;

                preg_match("/'[^']+'/", $script, $matches);

                $json = json_decode(substr($matches[0], 1, -1), true);

                if (isset($json['meta'][0]['request']) && $json['meta'][0]['request'] == $response_name) {
                    foreach ($json['data'][0] as $key => $value) {
                        $returnArray[] = array($display_name => $key, $value_name => $value);
                    }
                } else {
                    throw new Exception('Request for URL: ' . $url . ' gave inappropriate response: ' . $script . ' with values ' . print_r($valuesArr, true));
                }
            } else {
                throw new Exception('Missing script response with values ' . print_r($valuesArr, true));
            }
        } else {
            //class-book response from Follett's booklook system
            $items = array();

            $html = str_get_html($response);

            $course = $html->find('div[class=clsCourseSection]', 0);
            if (!$course) {
                throw new Exception('Error: HTML markup was unexpected, unable to parse.');
            }

            $error = $course->find('.efCourseErrorSection', 0);
            if ($error) {
                $error_text = $error->plaintext;
                if (!stripos($error_text, 'to be determined') &&
                        !stripos($error_text, 'no course materials required') &&
                        !stripos($error_text, 'no information received')) { //these are the two exceptions where there genuinely are 0 results.
                    throw new Exception('Error: ' . $error_text . ' on Follett booklook with values ' . print_r($valuesArr, true)); //we report the specific error that Follett's booklook gives us.
                } else {
                    $returnArray['Class_ID'] = $valuesArr['Class_ID'];
                    $returnArray['items'] = $items;

                    return $returnArray;
                }
            }

            foreach ($course->find('div[class=efCourseBody] ul li[class=material-group]') as $li) {
                // Each li represents required/optional/other

                $group_title = $li->find('h2[class=material-group-name]', 0);
                $required = $group_title->plaintext;
                if (strpos($required, '(')) {
                    $required = substr($required, 0, strpos($required, '('));
                }

                foreach ($li->find('li') as $book) {
                    $item = array(
                        'Necessity' => $required
                    );

                    foreach ($book->find('div[class=material-group-cover] span[id^=material]') as $info) {
                        switch ($info->getAttribute('id')) {
                            case 'materialTitleImage':
                                $image = $info->find('img', 0);
                                $item['Title'] = $image->alt;
                                break;
                            case 'materialAuthor':
                                $item['Authors'] = trim(substr($info->plaintext, strpos($info->plaintext, ':') + 1));
                                break;
                            case 'materialEdition':
                                $item['Edition'] = trim(substr($info->plaintext, strpos($info->plaintext, ':') + 1));
                                break;
                            case 'materialISBN':
                                $item['ISBN'] = trim(substr($info->plaintext, strpos($info->plaintext, ':') + 1));
                                break;
                            case 'materialCopyrightYear':
                                $item['Year'] = trim(substr($info->plaintext, strpos($info->plaintext, ':') + 1));
                                break;
                            case 'materialPublisher':
                                $item['Publisher'] = trim(substr($info->plaintext, strpos($info->plaintext, ':') + 1));
                                break;
                        }
                    }

                    foreach ($book->find('div[class=material-group-table] table tr[class=print_background]') as $tr) {
                        $buy_rent = $tr->find('td', 1);
                        $new_used = $tr->find('td', 2);
                        $price = $tr->find('td', 7);
                        if (strcmp($buy_rent->plaintext, 'BUY&nbsp;')) {
                            if (strcmp($new_used->plaintext, 'NEW&nbsp;')) {
                                $item['Bookstore_Price'] = $price->plaintext;
                                $item['New_Price'] = $item['Bookstore_Price'];
                            } else if (strcmp($new_used->plaintext, 'USED&nbsp;')) {
                                $item['Used_Price'] = $price->plaintext;
                                if (!isset($item['Bookstore_Price'])) {
                                    $item['Bookstore_Price'] = $item['Used_Price'];
                                }
                            }
                        } elseif (strcmp($buy_rent->plaintext, 'RENT&nbsp;')) {

                        }
                    }

                    if (isset($item['ISBN'])) {
                        $items[] = $item;
                    }
                }
            }

            $html->clear();
            unset($html);

            $returnArray['Class_ID'] = $valuesArr['Class_ID'];
            $returnArray['items'] = $items; //trim them all
            //print_r($items);
            //exit();
        }

        return $returnArray;
    } else {
        throw new Exception("No response with values " . print_r($valuesArr, true));
    }
}

function get_classes_and_items_from_epos($valuesArr) {
    if (isset($valuesArr['Term_ID']) && !isset($valuesArr['Division_ID'])) {
        //no divisions for ePOS
        return array();
    }

    $doc = new DOMDocument();
    $returnArray = array();

    $url = $valuesArr['Fetch_URL'] . '?';

    if ($valuesArr['Store_Value']) {
        $url .= 'store=' . $valuesArr['Store_Value'] . '&';
    }

    $user_agent = urlencode(random_user_agent());

    if (!isset($valuesArr['Term_ID'])) {
        $url .= 'form=shared3%2ftextbooks%2fno_jscript%2fmain.html&agent=' . $user_agent;

        $response_name = 'term';
        $display_name = 'Term_Name';
        $value_name = 'Term_Value';
    } else if (!isset($valuesArr['Department_ID'])) {
        $url .= 'wpd=1&step=2&listtype=begin&form=shared3%2Ftextbooks%2Fno_jscript%2Fmain.html&agent=' . $user_agent .
                '&TERM=' . urlencode($valuesArr['Term_Value']) .
                '&Go=Go';

        $response_name = 'department';
        $display_name = 'Department_Code';
        $value_name = 'Department_Value';
    } else if (!isset($valuesArr['Course_ID'])) {
        $url .= 'wpd=1&step=3&listtype=begin&form=shared3%2Ftextbooks%2Fno_jscript%2Fmain.html&agent=' . $user_agent .
                '&TERM=' . urlencode($valuesArr['Term_Value']) .
                '&department=' . urlencode($valuesArr['Department_Value']) .
                '&Go=Go';

        $response_name = 'course';
        $display_name = 'Course_Code';
        $value_name = 'Course_Value';
    } else if (!isset($valuesArr['Class_ID'])) {
        $url .= 'wpd=1&step=4&listtype=begin&form=shared3%2Ftextbooks%2Fno_jscript%2Fmain.html&agent=' . $user_agent .
                '&TERM=' . urlencode($valuesArr['Term_Value']) .
                '&department=' . urlencode($valuesArr['Department_Value']) .
                '&course=' . urlencode($valuesArr['Course_Value']) .
                '&Go=Go';

        $response_name = 'section';
        $display_name = 'Class_Code';
        $value_name = 'Class_Value';
    } else { //they sent a class
        $url .='wpd=1&step=5&listtype=begin&form=shared3%2Ftextbooks%2Fno_jscript%2Fmain.html&agent=' . $user_agent .
                '&TERM=' . urlencode($valuesArr['Term_Value']) .
                '&department=' . urlencode($valuesArr['Department_Value']) .
                '&course=' . urlencode($valuesArr['Course_Value']) .
                '&section=' . urlencode($valuesArr['Class_Value']) .
                '&Go=Go';
    }

    $response = curl_request(array(CURLOPT_URL => $url));

    if ($response) {
        @$doc->loadHTML($response); //because their HTML is imperfect
        $finder = new DomXPath($doc);

        if (!isset($valuesArr['Class_Value'])) { //dropdown response..
            $select = $finder->query('//select[@id="' . $response_name . '"]');
            if ($select->length != 0) {
                $select = $select->item(0);

                for ($i = 1; $i < $select->childNodes->length; $i++) { //we start at $i = 1 to skip the "- Select ... -"
                    $option = $select->childNodes->item($i);
                    if (isset($valuesArr['Course_Value'])) { //getting classes, so we parse instructors out
                        $split = explode('-', $option->nodeValue, 2); //split instructor
                        if (isset($split[1])) {
                            $returnArray[] = array($value_name => $option->getAttribute('value'), $display_name => $split[0], 'Instructor' => $split[1]);
                        } else {
                            $returnArray[] = array($value_name => $option->getAttribute('value'), $display_name => $option->nodeValue);
                        }
                        //split to get the instructor if possible.
                    } else {
                        $returnArray[] = array($value_name => $option->getAttribute('value'), $display_name => $option->nodeValue);
                    }
                }
            } else {
                throw new Exception('Missing select tag response with values ' . print_r($valuesArr, true));
            }
        } else {
            //class-items response..
            $returnArray['items'] = array();
            $cb_divs = $finder->query('//div[@id="info"]');

            foreach ($cb_divs as $cb_div) {
                $item = array();
                //Get the Title then remove it from the cb_div

                $title_span = $finder->query('.//span[@class="booktitle"]', $cb_div)->item(0);
                $item['Title'] = $title_span->getElementsByTagName('b')->item(0)->nodeValue;
                $cb_div->removeChild($title_span);

                //Get the Necessity then remove it from the cb_div
                $necc_span = $finder->query('.//b/span[@class="bookstatus"]', $cb_div)->item(0);
                $item['Necessity'] = $necc_span->nodeValue;
                $cb_div->removeChild($necc_span->parentNode);

                //remove the <p> that messes up with parsing if it's there
                $p = $cb_div->getElementsByTagName('p');
                if ($p->length) {
                    foreach ($p as $a_p) {
                        $cb_div->removeChild($a_p);
                    }
                }

                //get the inner html of the cb_div with that stuff removed
                $cb_div_html = innerHTML($cb_div);

                //Loop through that HTML and get the other fields\
                $fields = explode('<br>', $cb_div_html);
                foreach ($fields as $field) {
                    if ($field) {
                        $split_field = explode(':', $field, 2); //limit it to 2 elements so we only split on the first colon

                        if (trim($split_field[0])) {
                            switch (trim($split_field[0])) {
                                case 'ISBN':
                                    $item['ISBN'] = $split_field[1];
                                    break;
                                case 'Edition':
                                    $item['Edition'] = $split_field[1];
                                    break;
                                case 'Copyright Year':
                                    $item['Year'] = $split_field[1];
                                    break;
                                case 'Publisher':
                                    $item['Publisher'] = $split_field[1];
                                    break;
                                default:
                                    $item['Authors'] = $split_field[0];
                                    break;
                            }
                        }
                    }
                }

                // now get the prices
                $order_divs = $finder->query('.//div[@class="bookAttributes"]/span[@class="bookprice"]', $cb_div->parentNode);
                foreach ($order_divs as $order_div) {
                    if (stripos($order_div->nodeValue, 'new') !== false) {
                        $price = $finder->query('.//span', $order_div)->item(0);
                        $item['New_Price'] = $price->nodeValue;
                        $item['Bookstore_Price'] = $item['New_Price'];
                    } else { // used
                        $price = $finder->query('.//span', $order_div)->item(0);
                        $item['Used_Price'] = $price->nodeValue;
                        if (!isset($item['Bookstore_Price'])) {
                            $item['Bookstore_Price'] = $item['Used_Price'];
                        }
                    }
                }

                $returnArray['items'][] = $item;
            }
            $returnArray['Class_ID'] = $valuesArr['Class_ID'];
        }

        return $returnArray;
    } else {
        throw new Exception("No response with values " . print_r($valuesArr, true));
    }
}

function get_classes_and_items_from_eratex(array $valuesArr) {
    if (isset($valuesArr['Term_ID']) && !isset($valuesArr['Division_ID'])) {
        //no divisions for eRATEX
        return array();
    }

    if (substr($valuesArr['Fetch_URL'], -1) != '/') {
        $valuesArr['Fetch_URL'] .= '/';
    }

    $returnArray = array();

    $referer = $valuesArr['Fetch_URL'] . 'courselistbuilder.aspx';

    $url = $valuesArr['Fetch_URL'];

    $rnd = rand(100000, 999999);

    if (!isset($valuesArr['Term_ID'])) {
        $url .= 'x-page.clhandler.xml.config.aspx?page_name=term&t=1'
                . '&rnd=' . urlencode($rnd);

        $display_name = 'Term_Name';
        $value_name = 'Term_Value';
    } else if (!isset($valuesArr['Department_ID'])) {
        $url .= 'x-page.clhandler.xml.config.aspx?page_name=dept&d=1'
                . '&campus_id=' . urlencode($valuesArr['Campus_Value'])
                . '&term_id=' . urlencode($valuesArr['Term_Value'])
                . '&rnd=' . urlencode($rnd);

        $display_name = 'Department_Code';
        $value_name = 'Department_Value';
    } else if (!isset($valuesArr['Course_ID'])) {
        $url .= 'x-page.clhandler.xml.config.aspx?page_name=course&r=1'
                . '&campus_id=' . urlencode($valuesArr['Campus_Value'])
                . '&term_id=' . urlencode($valuesArr['Term_Value'])
                . '&dept_id=' . urlencode($valuesArr['Department_Value'])
                . '&rnd=' . urlencode($rnd);

        $display_name = 'Course_Code';
        $value_name = 'Course_Value';
    } else if (!isset($valuesArr['Class_ID'])) {
        $url .= 'x-page.clhandler.xml.config.aspx?page_name=section&s=1'
                . '&campus_id=' . urlencode($valuesArr['Campus_Value'])
                . '&term_id=' . urlencode($valuesArr['Term_Value'])
                . '&dept_id=' . urlencode($valuesArr['Department_Value'])
                . '&course_id=' . urlencode($valuesArr['Course_Value'])
                . '&rnd=' . urlencode($rnd);

        $display_name = 'Class_Code';
        $value_name = 'Class_Value';
    } else {
        //they sent a class
        // establish the session
        curl_request(array(
            CURLOPT_URL => $referer
        ));

        $url .= 'x-page.clselected.xml.config.aspx?addcourse=true'
                . '&course_id=' . urlencode($valuesArr['Class_Value'])
                . '&rnd=' . urlencode($rnd);

        curl_request(array(
            CURLOPT_URL => $url,
            CURLOPT_REFERER => $referer
        ));

        $url = $valuesArr['Fetch_URL'] . 'booklist.aspx';
    }

    $response = curl_request(array(
        CURLOPT_URL => $url,
        CURLOPT_REFERER => $referer
    ));

    if ($response) {
        if (!isset($valuesArr['Class_Value'])) { //dropdown response..
            $response = substr($response, strlen($rnd . '~'), -1);
            $items = explode('^', $response);

            foreach ($items as $item) {
                $option = explode('~', $item);

                $returnArray[] = array(
                    $value_name => $option[0],
                    $display_name => $option[1]
                );
            }
        } else { //class-items response..
            $doc = new DOMDocument();
            @$doc->loadHTML($response); //because their HTML is imperfect

            $finder = new DomXPath($doc);

            $returnArray['items'] = array();
            $nobooks = $finder->query('//li[@class="nobooks"]');
            if (!$nobooks->length) {
                $book_tables = $finder->query('//table[@class="books"]');
                foreach ($book_tables as $book_table) {
                    $requirement_th = $finder->query('.//th[@class="first"]/h3', $book_table)->item(0);
                    $requirement = $requirement_th->nodeValue;

                    $product_divs = $finder->query('.//div[@class="productoverlay"]', $book_table);
                    foreach ($product_divs as $product_div) {
                        $item = array('Necessity' => $requirement);

                        $title_div = $finder->query('.//div[@class="ProductNameText"]/a', $product_div)->item(0);
                        $item['Title'] = $title_div->nodeValue;

                        $details_lis = $finder->query('.//ul/li', $product_div);
                        foreach ($details_lis as $details_li) {
                            $li_value = $details_li->nodeValue;

                            $label = substr($li_value, 0, strpos($li_value, ':'));
                            $value = substr($li_value, strlen($label) + 2);
                            switch ($label) {
                                case 'Author':
                                    $item['Authors'] = $value;
                                    break;
                                case 'ISBN':
                                    $item['ISBN'] = $value;
                                    break;
                                case 'Publisher':
                                    $item['Publisher'] = $value;
                                    break;
                            }
                        }
                        //echo innerHTML($product_div);
                        $price_divs = $finder->query('.//div[@class="prices"]/div', $product_div);
                        foreach ($price_divs as $price_div) {
                            $label = substr($price_div->nodeValue, 0, strpos($price_div->nodeValue, ':'));
                            $price = $finder->query('.//span[@class="VariantPrice"]', $price_div)->item(0);

                            if (!$price) { // if not a variant price, maybe a sale price
                                $price = $finder->query('.//span[@class="SalePrice"]', $price_div)->item(0);
                            }
                            if (!$price) { // if not a sale price, maybe a regular price
                                $price = $finder->query('.//span[@class="RegularPrice"]', $price_div)->item(0);
                            }

                            switch ($label) {
                                case 'NEW':
                                    $item['New_Price'] = $price->nodeValue;
                                    $item['Bookstore_Price'] = $item['New_Price'];
                                    break;
                                case 'USED':
                                    $item['Used_Price'] = $price->nodeValue;
                                    if (!isset($item['Bookstore_Price'])) {
                                        $item['Bookstore_Price'] = $item['Used_Price'];
                                    }
                                    break;
                            }
                        }

                        $returnArray['items'][] = $item;
                    }
                }
                $returnArray['Class_ID'] = $valuesArr['Class_ID'];
            }
        }

        return $returnArray;
    } else {
        throw new Exception("No response with values " . print_r($valuesArr, true));
    }
}

function get_classes_and_items_from_mbs($valuesArr) {
    if (isset($valuesArr['Term_ID']) && !isset($valuesArr['Division_ID'])) {
        //because there are no divisions on MBS
        return array();
    }
    $failSafe = 0;

    $doc = new DOMDocument();

    $useragent = 'Mozilla/5.0 (iPhone; U; CPU iPhone OS 3_0 like Mac OS X; en-us) AppleWebKit/528.18 (KHTML, like Gecko) Version/4.0 Mobile/7A341 Safari/528.16'; //IPhone useragent, because we fetch from the easy to scrape mobile version.

    $value_names = array('Term_Value', 'Department_Value', 'Course_Value', 'Class_Value');
    $display_names = array('Term_Name', 'Department_Code', 'Course_Code', 'Class_Code');

    $mbs_url = $valuesArr['Fetch_URL'] . 'textbooks.aspx';

    do {
        $failSafe++;
        if (!isset($valuesArr['Term_ID']) || !isset($dd_state)) {
            if (!isset($btnRegular) || !$btnRegular) {
                //initial terms request to establish a session
                $options = array(CURLOPT_URL => $mbs_url,
                    CURLOPT_USERAGENT => $useragent,
                    CURLOPT_COOKIESESSION => true);
            } else {
                //make the btn regular request
                $options = array(CURLOPT_URL => $mbs_url,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => '__VIEWSTATE=' . urlencode($mbs_viewstate) . '&btnRegular=Browse+Course+Listing&txtSearch=',
                    CURLOPT_USERAGENT => $useragent);
            }
        } else {
            if (isset($mbs_terms) && array_key_exists($valuesArr['Term_Value'], $mbs_terms)) {
                $mbs_form_term_value = $mbs_terms[$valuesArr['Term_Value']] . '=' . urlencode($valuesArr['Term_Value']);
                $mbs_form_eventtarget = $mbs_terms[$valuesArr['Term_Value']];
            } else {
                // fallback but this shouldn't happen
                $mbs_form_term_value = $mbs_term_name . '=' . urlencode($valuesArr['Term_Value']);
                $mbs_form_eventtarget = $mbs_term_name;
            }

            if (!isset($valuesArr['Department_ID']) || $dd_state < 1) {
                $options = array(CURLOPT_URL => $mbs_url,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => '__VIEWSTATE=' . urlencode($mbs_viewstate) . '&__EVENTTARGET=' . $mbs_form_eventtarget . '&__EVENTARGUMENT=&' . $mbs_form_term_value . '&' . $mbs_dept_name . '=0&' . $mbs_course_name . '=0&' . $mbs_section_name . '=0',
                    CURLOPT_USERAGENT => $useragent);
                $dd_state = 1;
            } else if (!isset($valuesArr['Course_ID']) || $dd_state < 2) {
                $options = array(CURLOPT_URL => $mbs_url,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $mbs_form_term_value . '&' . $mbs_dept_name . '=' . urlencode($valuesArr['Department_Value']) . '&' . $mbs_course_name . '=0&' . $mbs_section_name . '=0&__VIEWSTATE=' . urlencode($mbs_viewstate),
                    CURLOPT_USERAGENT => $useragent);
                $dd_state = 2;
            } else if (!isset($valuesArr['Class_ID']) || $dd_state < 3) {
                $options = array(CURLOPT_URL => $mbs_url,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $mbs_form_term_value . '&' . $mbs_dept_name . '=' . urlencode($valuesArr['Department_Value']) . '&' . $mbs_course_name . '=' . urlencode($valuesArr['Course_Value']) . '&' . $mbs_section_name . '=0&__VIEWSTATE=' . urlencode($mbs_viewstate),
                    CURLOPT_USERAGENT => $useragent);
                $dd_state = 3;
            } else { //class-item request
                $options = array(CURLOPT_URL => $mbs_url,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $mbs_form_term_value . '&' . $mbs_dept_name . '=' . urlencode($valuesArr['Department_Value']) . '&' . $mbs_course_name . '=' . urlencode($valuesArr['Course_Value']) . '&' . $mbs_section_name . '=' . urlencode($valuesArr['Class_Value']) . '&__VIEWSTATE=' . urlencode($mbs_viewstate),
                    CURLOPT_USERAGENT => $useragent);
                $dd_state = 4;
            }
        }
        //time to make the response
        $response = curl_request($options);

        if (!$response) {
            throw new Exception('No response with values ' . print_r($valuesArr, true));
        } else {
            @$doc->loadHTML($response); //because their HTML is malformed.

            if (!isset($dd_state) || $dd_state != 4) { //not a class-item response
                $form_tag = $doc->getElementsByTagName('form');
                if ($form_tag->length == 0) {
                    throw new Exception('No form in response with values ' . print_r($valuesArr, true));
                } else {
                    $form = $form_tag->item(0);
                    $input_tags = $doc->getElementsByTagName('input');
                    if ($input_tags->length == 0) {
                        throw new Exception('No input in response with values ' . print_r($valuesArr, true));
                    } else {
                        //form and input tags are there as they're supposed to be
                        $input = $input_tags->item(0);
                        //update the state session stuff..
                        $mbs_url = $valuesArr['Fetch_URL'] . $form->getAttribute('action');
                        $mbs_viewstate = $input->getAttribute('value');

                        if (!isset($valuesArr['Term_Value']) || !isset($dd_state)) { //they're getting terms.
                            $finder = new DomXPath($doc);

                            $btnRegular = $finder->query('//input[@name="btnRegular"]');

                            if ($btnRegular->length != 0) { //btnRegular step..
                                //note that we don't update $dd_state until they've made it past btnRegular stage.
                                $btnRegular = true; //just store a boolean now, so it will go back..
                                continue;
                            } else {
                                if (!isset($dd_state)) {
                                    $mbs_start = 0;

                                    $select_tags = $doc->getElementsByTagName('select');

                                    if ($select_tags->length == 0) {
                                        throw new Exception('No select tags available on initial terms request with values ' . print_r($valuesArr, true));
                                    } else {
                                        $mbs_check_for_inquire_term = false;
                                        if ($select_tags->length > 4) { //special cases where term isn't the first dropdown, we want to start with term correctly.
                                            $mbs_start = $select_tags->length - 4;

                                            // check for an inquire term
                                            $mbs_inquire_term_name = urlencode($select_tags->item($mbs_start - 1)->getAttribute('name'));
                                            if (stripos($mbs_inquire_term_name, 'TermInq') === false) {
                                                unset($mbs_inquire_term_name);
                                            }
                                        }

                                        $mbs_term_name = urlencode($select_tags->item($mbs_start)->getAttribute('name'));
                                        $mbs_dept_name = urlencode($select_tags->item($mbs_start + 1)->getAttribute('name'));
                                        $mbs_course_name = urlencode($select_tags->item($mbs_start + 2)->getAttribute('name'));
                                        $mbs_section_name = urlencode($select_tags->item($mbs_start + 3)->getAttribute('name'));

                                        // we need to get the terms
                                        $mbs_terms = array();
                                        $select = $select_tags->item($mbs_start);
                                        for ($i = 1; $i < $select->childNodes->length; $i++) { //we start at $i = 1 to skip the "select"
                                            $option = $select->childNodes->item($i);
                                            $mbs_terms[$option->getAttribute('value')] = $mbs_term_name;
                                        }

                                        if (isset($mbs_inquire_term_name)) {
                                            $select = $select_tags->item($mbs_start - 1);
                                            for ($i = 1; $i < $select->childNodes->length; $i++) { //we start at $i = 1 to skip the "select"
                                                $option = $select->childNodes->item($i);
                                                $mbs_terms[$option->getAttribute('value')] = $mbs_inquire_term_name;
                                            }
                                        }
                                    }
                                }
                                $dd_state = 0;
                            }
                        }

                        if (!isset($valuesArr[$value_names[$dd_state]])) { //this is the one they want returned
                            $select_tags = $doc->getElementsByTagName('select');
                            if ($select_tags->length == 0) {
                                throw new Exception('No select tag in response with values ' . print_r($valuesArr, true));
                            } else {
                                //build the returnArray based on the select.. then we're good to go.
                                $returnArray = array();

                                $select = $select_tags->item($mbs_start + $dd_state);

                                for ($i = 1; $i < $select->childNodes->length; $i++) { //we start at $i = 1 to skip the "select"
                                    $option = $select->childNodes->item($i);
                                    $returnArray[] = array($value_names[$dd_state] => $option->getAttribute('value'), $display_names[$dd_state] => $option->nodeValue);
                                }

                                if ($dd_state == 0 && isset($mbs_inquire_term_name)) {
                                    $select = $select_tags->item($mbs_start - 1);

                                    for ($i = 1; $i < $select->childNodes->length; $i++) { //we start at $i = 1 to skip the "select"
                                        $option = $select->childNodes->item($i);
                                        $returnArray[] = array($value_names[$dd_state] => $option->getAttribute('value'), $display_names[$dd_state] => trim($option->nodeValue) . ' (Inquire Only)');
                                    }
                                }
                            }
                        }
                    }
                }
            } else { //class-item response to handle
                $items = array();

                $finder = new DomXPath($doc);

                $table_tags = $doc->getElementsByTagName('table'); //each table is a class-item

                if ($table_tags->length != 0) {
                    for ($i = 0; $i < $table_tags->length; $i++) {
                        $table_tag = $table_tags->item($i);

                        $font_tags = $table_tag->getElementsByTagName('font');

                        if ($font_tags->item(0)->hasChildNodes()) {
                            $items[$i]['Necessity'] = $font_tags->item(0)->firstChild->nodeValue;
                        } else {
                            $items[$i]['Necessity'] = $font_tags->item(0)->nodeValue;
                        }

                        $second_td = $finder->query('.//td[2]', $table_tag)->item(0);

                        $title = $finder->query('.//font', $second_td);

                        $items[$i]['Title'] = $title->item(0)->nodeValue;

                        $td_doc = new DOMDocument();

                        $td_doc->appendChild($td_doc->importNode($second_td, true)); //we parse second_td to get the remaining stuff..

                        $td_lines = $td_doc->saveHTML();

                        /* Added New_Rental And Used_Rental here */

                        if ($new_rental_start = strpos($td_lines, 'New Rental: </label>')) {
                            $new_rental_start += strlen('New Rental: </label>');
                            $new_rental_end = strpos($td_lines, '<br>', $new_rental_start);

                            $items[$i]['New_Rental_Price'] = substr($td_lines, $new_rental_start, $new_rental_end - $new_rental_start);
                        }

                        if ($used_rental_start = strpos($td_lines, 'Used Rental: </label>')) {
                            $used_rental_start += strlen('Used Rental: </label>');
                            $used_rental_end = strpos($td_lines, '<br>', $used_rental_start);

                            $items[$i]['Used_Rental_Price'] = substr($td_lines, $used_rental_start, $used_rental_end - $used_rental_start);
                        }

                        if ($new_price_start = strpos($td_lines, 'New:</label>')) {
                            $new_price_start += strlen('New:</label>');
                            $new_price_end = strpos($td_lines, '<br>', $new_price_start);

                            $items[$i]['New_Price'] = substr($td_lines, $new_price_start, $new_price_end - $new_price_start);
                            $items[$i]['Bookstore_Price'] = $items[$i]['New_Price'];
                        }

                        if ($used_price_start = strpos($td_lines, 'Used:</label>')) {

                            $used_price_start += strlen('Used:</label>');
                            $used_price_end = strpos($td_lines, '<br>', $used_price_start);
                            $items[$i]['Used_Price'] = substr($td_lines, $used_price_start, $used_price_end - $used_price_start);
                            if (!isset($items[$i]['Bookstore_Price'])) {
                                $items[$i]['Bookstore_Price'] = $items[$i]['Used_Price'];
                            }
                        }

                        $td_lines = explode('<br>', $td_lines);

                        foreach ($td_lines as $td_line) {
                            $td_line = explode('</b>', $td_line);
                            switch (trim($td_line[0])) {
                                case '<b>Author:':
                                    $items[$i]['Authors'] = $td_line[1];
                                    break;
                                case '<b>ISBN:':
                                    $items[$i]['ISBN'] = $td_line[1];
                                    break;
                                case '<b>Edition/Copyright:':
                                    $items[$i]['Edition'] = $td_line[1];
                                    break;
                                case '<b>Publisher:':
                                    $items[$i]['Publisher'] = $td_line[1];
                                    break;
                                case '<b>Published Date:':
                                    if (trim($td_line[1]) != "NA") {
                                        $items[$i]['Year'] = $td_line[1];
                                    }
                                    break;
                            }
                        }
                    }
                }

                $returnArray['Class_ID'] = $valuesArr['Class_ID'];

                //we have to remove the &nbsp; gunk
                foreach ($items as $key => $val) {
                    $items[$key] = str_replace('&nbsp;', '', $val);
                }

                $returnArray['items'] = $items; //trim them all
            }
        }
    } while ((!isset($dd_state) || isset($valuesArr[$value_names[$dd_state]])) && $failSafe < 10); //the !isset($dd_state) is for the btnRegular situation.

    return $returnArray;
}

// This is the php version and sites normally have mbsdirect.net in their domain
// The other MBS is MBS Textbook Exchange, Inc. Probably not the same company?
function get_classes_and_items_from_mbsdirect(array $valuesArr) {
    // TODO implement when there is demand?
    /*
     * Somet notes:
     * http://bookstore.mbsdirect.net/collegeofidaho.htm
     * Textbook buying entrance page: http://bookstore.mbsdirect.net/vb_buy.php?ACTION=top&FVCUSNO=37411&VCHI=1
     * store id: 37411
     * Example with campuses: http://bookstore.mbsdirect.net/vb_buy.php?ACTION=top&FVCUSNO=5320&VCHI=1
     */
}

function get_classes_and_items_from_campushub($valuesArr) {
    if (isset($valuesArr['Term_ID']) && !isset($valuesArr['Division_ID'])) {
        //No such thing as Divisions on CampusHub
        return array();
    }

    $url = dirname($valuesArr['Fetch_URL']) . '/textbooks_xml.asp';

    if (!isset($valuesArr['Term_ID'])) {
        $options = array(CURLOPT_URL => $valuesArr['Fetch_URL']);
        $response_name = 'selTerm';
        $display_name = 'Term_Name';
        $value_name = 'Term_Value';
    } else {
        //CampusHub has a bit of a weird Campus/Term value system, because they use different combinations of these values for the Term dropdown but there's never a different campus dropdown.  Accordingly we bunch these together as 'Term_Value' in the DB.
        $campus_and_term = explode('|', $valuesArr['Term_Value']);
        $campus = $campus_and_term[0];
        $term = $campus_and_term[1];

        if (!isset($valuesArr['Department_ID'])) {
            $options = array(CURLOPT_URL => $url . '?control=campus&campus=' . $campus . '&term=' . $term . '&t=' . time(),
                CURLOPT_REFERER => $valuesArr['Fetch_URL']);
            $response_name = 'departments';
            $display_name = 'Department_Code';
            $value_name = 'Department_Value';
        } else if (!isset($valuesArr['Course_ID'])) {
            $options = array(CURLOPT_URL => $url . '?control=department&dept=' . $valuesArr['Department_Value'] . '&term=' . $term . '&t=' . time(),
                CURLOPT_REFERER => $valuesArr['Fetch_URL']);
            $response_name = 'courses';
            $display_name = 'Course_Code';
            $value_name = 'Course_Value';
        } else if (!isset($valuesArr['Class_ID'])) {

            $options = array(CURLOPT_URL => $url . '?control=course&course=' . $valuesArr['Course_Value'] . '&term=' . $term . '&t=' . time(),
                CURLOPT_REFERER => $valuesArr['Fetch_URL']);
            $response_name = 'sections';
            $display_name = 'Class_Code';
            $value_name = 'Class_Value';
        } else { //they sent a class
            $options = array(CURLOPT_URL => $url . '?control=section&section=' . $valuesArr['Class_Value'] . '&t=' . time(), CURLOPT_REFERER => $valuesArr['Fetch_URL']);
        }
    }

    $response = curl_request($options);

    if (!$response) {
        throw new Exception('No response with values ' . print_r($valuesArr, true));
    } else {
        $returnArray = array();

        $doc = new DOMDocument();

        @$doc->loadHTML($response); //suppress the error cus the html is imperfect
        $finder = new DomXPath($doc);

        if (!isset($valuesArr['Class_Value'])) { //dropdown request
            if (!isset($valuesArr['Term_Value'])) {
                $select = $finder->query('//*[@name="' . $response_name . '"]');
                $start = 1; //skip "--Select a Campus term--
            } else {
                $select = $doc->getElementsByTagName($response_name);
                $start = 0;
            }

            if ($select->length == 0) {
                throw new Exception('No select in response with values ' . print_r($valuesArr, true));
            } else {
                $options = $select->item(0)->childNodes;
                for ($i = $start; $i < $options->length; $i++) { //skip the first "Select an X" type option
                    $arr = array();
                    $option = $options->item($i);
                    //hmm, response handling actually varies somewhat. lets see which aspects do..
                    if (!isset($valuesArr['Term_Value'])) {
                        $arr[$value_name] = $option->getAttribute('value');
                        $arr[$display_name] = $option->nodeValue;
                    } else if (!isset($valuesArr['Department_Value'])) { //Depts request
                        $arr[$value_name] = $option->getAttribute('id');
                        $arr[$display_name] = $option->getAttribute('abrev');
                    } else if (!isset($valuesArr['Course_Value'])) {
                        $arr[$value_name] = $option->getAttribute('id');
                        $arr[$display_name] = $option->getAttribute('name');
                    } else { //they're getting classes in response.
                        //we have to do some special stuff because they sometimes use only the instructor as the display name.
                        if ($option->getAttribute('instructor')) {
                            $arr['Instructor'] = $option->getAttribute('instructor');
                        }
                        if ($option->getAttribute('name')) {
                            $arr[$display_name] = $option->getAttribute('name');
                        }

                        $arr[$value_name] = $option->getAttribute('id');
                    }
                    $returnArray[] = $arr;
                }
            }
        } else { //class-items request
            $items = array();

            $tbody_tags = $doc->getElementsByTagName('tbody');
            if ($tbody_tags->length != 0) {
                $tbody_tag = $tbody_tags->item(0);
                $count = 0;

                $tr_tags = $tbody_tag->childNodes;
                if ($tr_tags->length) {
                    foreach ($tr_tags as $book_tr) {
                        $item = array(); //item we will add if there's a Title.

                        $necessity_tag = $finder->query('.//*[@class="book-req"]', $book_tr);

                        if ($necessity_tag->length) {
                            $item['Necessity'] = $necessity_tag->item(0)->nodeValue;
                        }

                        $spans = $book_tr->getElementsByTagName('span');

                        for ($i = 0; $i < $spans->length; $i++) {
                            $span = $spans->item($i);
                            switch (trim($span->getAttribute('class'))) {
                                case 'book-title':
                                    $item['Title'] = $span->nodeValue;
                                    break;
                                case 'book-meta book-author':
                                    $item['Authors'] = $span->nodeValue;
                                    break;
                                case 'isbn':
                                    $item['ISBN'] = $span->nodeValue;
                                    break;
                                case 'book-meta book-copyright':
                                    $item['Year'] = str_replace(array(' ', '�', 'Copyright'), '', $span->nodeValue);
                                    break;
                                case 'book-meta book-publisher':
                                    $item['Publisher'] = str_replace(array(' ', '�', 'Publisher'), '', $span->nodeValue);
                                    break;
                                case 'book-meta book-edition':
                                    $item['Edition'] = str_replace(array(' ', '�', 'Edition'), '', $span->nodeValue);
                                    break;
                                case 'book-price-new':
                                    $item['Bookstore_Price'] = $span->nodeValue;
                                    break;
                                case 'book-price-used':
                                    if (!isset($item['Bookstore_Price'])) { //only new used when new isn't there.
                                        $item['Bookstore_Price'] = $span->nodeValue;
                                    }
                                    break;
                            }
                        }

                        //sometimes Bookstore Price is listed in a different way (radio labels)
                        if (!isset($item['Bookstore_Price'])) {
                            $prices = $finder->query('.//td[@class="price"]/label', $book_tr);
                            $priceArr = array();

                            foreach ($prices as $price) {
                                $priceArr[] = priceFormat($price->nodeValue); //format it so we can compare
                            }

                            if ($priceArr) {
                                $item['Bookstore_Price'] = max($priceArr);
                            }
                        }

                        if (isset($item['Title'])) {
                            $items[$count] = $item;

                            $count++;
                        }
                    }
                }
            }

            $returnArray['Class_ID'] = $valuesArr['Class_ID'];
            $returnArray['items'] = $items;
        }
    }
    return $returnArray;
}

function get_classes_and_items_from_bn(array $valuesArr) {
    if (isset($valuesArr['Term_ID']) && !isset($valuesArr['Division_ID'])) {
        return array(); //because BN doesn't have Division values.
    }

    $url = $valuesArr['Fetch_URL'] . 'webapp/wcs/stores/servlet/';

    $referer = $url . 'TBWizardView?catalogId=10001&storeId=' . $valuesArr['Store_Value'] . '&langId=-1';

    if (!isset($valuesArr['Class_ID'])) {
        //make initialization request if they don't have a session yet...
        $response = curl_request(array(
            CURLOPT_URL => $valuesArr['Storefront_URL'],
            CURLOPT_COOKIESESSION => true,
        ));

        if (!$response) {
            throw new Exception('Failed to initialize the BN session with values ' . print_r($valuesArr, true));
        }

        //prepare appropriate dropdown query depending on what they're trying to get...
        if (!isset($valuesArr['Term_ID'])) {
            //We're doing this Multiple_Campuses thing for now, until we improve the system..
            if ($valuesArr['Multiple_Campuses'] == 'Y') { //they have a campus dropdown.
                $url .= 'TextBookProcessDropdownsCmd?campusId=' . $valuesArr['Campus_Value'] . '&termId=&deptId=&courseId=&sectionId=&storeId=' . $valuesArr['Store_Value'] . '&catalogId=10001&langId=-1dropdown=campus';
            } else {
                $url = $referer;
            }
        } else if (!isset($valuesArr['Department_ID'])) {
            $url .= 'TextBookProcessDropdownsCmd?campusId=' . $valuesArr['Campus_Value'] . '&termId=' . $valuesArr['Term_Value'] . '&deptId=&courseId=&sectionId=&storeId=' . $valuesArr['Store_Value'] . '&catalogId=10001&langId=-1&dropdown=term';
            $response_value = 'Department_Value';
            $response_name = 'Department_Code';
        } else if (!isset($valuesArr['Course_ID'])) {
            $url .= 'TextBookProcessDropdownsCmd?campusId=' . $valuesArr['Campus_Value'] . '&termId=' . $valuesArr['Term_Value'] . '&deptId=' . $valuesArr['Department_Value'] . '&courseId=&sectionId=&storeId=' . $valuesArr['Store_Value'] . '&catalogId=10001&langId=-1&dropdown=dept';
            $response_value = 'Course_Value';
            $response_name = 'Course_Code';
        } else if (!isset($valuesArr['Class_ID'])) {
            $url .= 'TextBookProcessDropdownsCmd?campusId=' . $valuesArr['Campus_Value'] . '&termId=' . $valuesArr['Term_Value'] . '&deptId=' . $valuesArr['Department_Value'] . '&courseId=' . $valuesArr['Course_Value'] . '&sectionId=&storeId=' . $valuesArr['Store_Value'] . '&catalogId=10001&langId=-1&dropdown=course';
            $response_value = 'Class_Value';
            $response_name = 'Class_Code';
        }

        $options = array(
            CURLOPT_URL => $url,
            CURLOPT_REFERER => $referer,
        );
    } else { //prepare the class-items query
        $options = array(
            CURLOPT_URL => $url . 'TBListView',
            CURLOPT_REFERER => $referer,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => array(
                'campus1' => $valuesArr['Campus_Value'],
                'catalogId' => '10001',
                'clearAll' => '',
                'firstTermId_' . $valuesArr['Campus_Value'] => $valuesArr['Term_Value'],
                'firstTermName_' . $valuesArr['Campus_Value'] => $valuesArr['Term_Name'],
                'langId' => '-1',
                'mcEnabled' => 'N',
                'numberOfCourseAlready' => '0',
                'removeSectionId' => '',
                'section_1' => $valuesArr['Class_Value'],
                'selectCourse' => 'Select Course',
                'selectDepartment' => 'Select Department',
                'selectSection' => 'Select Section',
                'selectTerm' => 'Select Term',
                'showCampus' => $valuesArr['Multiple_Campuses'] == 'Y' ? 'true' : 'false',
                'storeId' => $valuesArr['Store_Value'],
                'viewName' => 'TBWizardView'
            ),
        );
    }

    $response = curl_request($options);

    if (!$response) {
        throw new Exception('Failed to get a response with values ' . print_r($valuesArr, true));
    } else {
        $returnArray = array();

        //time to process the response...
        if (!isset($valuesArr['Term_Value'])) {
            if ($valuesArr['Multiple_Campuses'] == 'Y') {
                $terms = json_decode($response);
                if (!$terms) {
                    throw new Exception('Failed to get term select with values ' . print_r($valuesArr, true));
                }
                foreach ($terms as $term) {
                    $returnArray[] = array('Term_Value' => $term->categoryId, 'Term_Name' => $term->title);
                }
            } else {
                $html = str_get_html($response);
                $term_ul = $html->find('ul[class=termOptions]', 0);
                if (!$term_ul) {
                    throw new Exception('Failed to get term select with values ' . print_r($valuesArr, true));
                }
                foreach ($term_ul->find('li') as $li) {
                    $returnArray[] = array('Term_Value' => $li->getAttribute('data-optionvalue'), 'Term_Name' => html_entity_decode($li->plaintext));
                }
                $html->clear();
                unset($html);
            }
        } else if (!isset($valuesArr['Department_Value']) || !isset($valuesArr['Course_Value']) || !isset($valuesArr['Class_Value'])) {
            $json = json_decode($response);
            if (!$json) {
                throw new Exception('Failed to get dropdown json with values ' . print_r($valuesArr, true));
            }
            foreach ($json as $item) {
                $returnArray[] = array($response_value => $item->categoryId, $response_name => $item->title);
            }

            /* In the class response there is an extra field that says if the course has books or not, would be cool to save that
              if (!isset($valuesArr['Class_Value'])) {
              foreach ($json as $item) {
              $item->field1 == 'Y' // there are no books for this class
              }
              }
             */
        } else { //continue with class items search
            $items = array();
            $html = str_get_html($response);

            foreach ($html->find('.book_details') as $book) {
                $item = array();

                // first get the description info
                $description_em = $book->find('.book_desc1', 0);
                $item['Title'] = html_entity_decode($description_em->find('h1 a', 0)->title);

                $required_em = $description_em->find('span[class=recommendBookType]', 0);
                $item['Necessity'] = substr($required_em->innertext, 0, strpos($required_em->innertext, '<input'));

                $author_em = $description_em->find('span[!class]', 0)->find('i', 0);
                $item['Authors'] = html_entity_decode(substr($author_em->innertext, stripos($author_em->innertext, 'By ') + 3));

                foreach ($description_em->find('ul[!class]', 0)->find('li') as $li) {
                    $li_text = str_replace('<br />', '', str_replace('&nbsp;', '', $li->innertext));
                    $value = substr($li_text, strpos($li_text, '</strong>') + 9);

                    switch (trim($li->find('strong', 0)->innertext)) {
                        case 'EDITION:':
                            $item['Edition'] = $value;
                            break;
                        case 'PUBLISHER:':
                            $item['Publisher'] = $value;
                            break;
                        case 'ISBN:':
                            $item['ISBN'] = $value;
                            break;
                    }
                }

                // now pricing
                foreach ($book->find('ul[class=cm_tb_bookList] li') as $li) {
                    $price = $li->find('span[class=bookPrice]', 0)->title;
                    switch ($li->title) {
                        case 'RENT USED':
                            $item['New_Rental_Price'] = $price;
                            break;
                        case 'RENT NEW':
                            $item['Used_Rental_Price'] = $price;
                            break;
                        case 'BUY USED ':
                            $item['Used_Price'] = $price;
                            if (!isset($item['Bookstore_Price'])) {
                                $item['Bookstore_Price'] = $price;
                            }
                            break;
                        case 'BUY NEW ':
                            $item['New_Price'] = $price;
                            $item['Bookstore_Price'] = $price;
                            break;
                    }
                }

                $items[] = $item;
            }

            $html->clear();
            unset($html);

            $returnArray['Class_ID'] = $valuesArr['Class_ID'];
            $returnArray['items'] = $items; //trim them all
        }

        return $returnArray;
    }
}

function update_classes_from_bookstore($valuesArr) { //$valuesArr is an array of values to send to the bookstore (usually its from a $row result).  Depending on what's there, we query the next thing:  Bookstore vars, Term_Value, Department_Value, Course_Value.
    $wait_times = array(FALSE, 250000, 400000); //double retries
    $results = false;

    for ($n = 0; $n < count($wait_times) && !$results; $n++) {
        if ($wait_times[$n]) {
            usleep($wait_times[$n]);
        }
        try { //we need to catch exceptions because they might change their layouts
            switch ($valuesArr['Bookstore_Type_Name']) {
                case 'Barnes and Nobles':
                    $results = get_classes_and_items_from_bn($valuesArr);
                    break;
                case 'CampusHub':
                    $results = get_classes_and_items_from_campushub($valuesArr);
                    break;
                case 'MBS':
                    $results = get_classes_and_items_from_mbs($valuesArr);
                    break;
                case 'Follett':
                    $results = get_classes_and_items_from_follett($valuesArr);
                    break;
                case 'ePOS':
                    $results = get_classes_and_items_from_epos($valuesArr);
                    break;
                case 'Neebo':
                    $results = get_classes_and_items_from_neebo($valuesArr);
                    break;
                case 'eRATEX':
                    $results = get_classes_and_items_from_eratex($valuesArr);
                    break;
                case 'Textbook Tech':
                    $results = get_classes_and_items_from_textbooktech($valuesArr);
                    break;
                default:
                    throw new Exception("Unrecognized bookstore type for classes: {$valuesArr['Bookstore_Type_Name']}");
                    break;
                //These functions will return false or empty array on error or 0 $results...
            }
        } catch (Exception $e) {
            $results = false;
            trigger_error('Bookstore query problem: ' . $e->getMessage() . ' on line ' . $e->getLine());
        }
    }

    if (!$conn = connect()) {
        trigger_error('Connect failure', E_USER_WARNING);
    }

    if ($results !== false) {
        foreach ($results as $key => $result) {
            $results[$key] = format_dropdown($result);
        }

        if ($results && !isset($valuesArr['Term_ID'])) { //it's getting terms
            $query = 'INSERT INTO Terms_Cache (Campus_ID, Term_Name, Term_Value) VALUES ';
            foreach ($results as $term) {
                $query .= '(' . $valuesArr['Campus_ID'] . ', "' . mysql_real_escape_string($term['Term_Name']) . '", "' . mysql_real_escape_string($term['Term_Value']) . '"),'; //Title Capitalize the Term_Name with ucwords()
            }
            $query = substr(($query), 0, -1); //remove final comma
            $query .= ' ON DUPLICATE KEY UPDATE Term_Name=VALUES(Term_Name),Cache_TimeStamp=NOW()';
        } else if (!isset($valuesArr['Division_ID'])) { //no reuslts is interpreted as placeholder no division null insert.
            $query = 'INSERT INTO Divisions_Cache (Term_ID, Division_Name, Division_Value) VALUES ';
            //we allow for empty results on this one
            if (!$results) {
                //insert NULL placeholder row
                $query .= '(' . $valuesArr['Term_ID'] . ', NULL, NULL)';
            } else {
                //insert actual programs
                foreach ($results as $program) {
                    $query .= '(' . $valuesArr['Term_ID'] . ', "' . mysql_real_escape_string($program['Division_Name']) . '", "' . mysql_real_escape_string($program['Division_Value']) . '"),';
                }
                $query = substr(($query), 0, -1); //remove final comma
            }

            $query .= ' ON DUPLICATE KEY UPDATE Division_Name=VALUES(Division_Name),Cache_TimeStamp=NOW()';
        } else if ($results && !isset($valuesArr['Department_ID'])) { //it's getting departments
            $query = 'INSERT INTO Departments_Cache (Division_ID, Department_Code, Department_Value) VALUES ';
            foreach ($results as $dept) {
                $query .= '(' . $valuesArr['Division_ID'] . ', "' . mysql_real_escape_string($dept['Department_Code']) . '", "' . mysql_real_escape_string($dept['Department_Value']) . '"),';
            }
            $query = substr(($query), 0, -1); //remove final comma
            $query .= ' ON DUPLICATE KEY UPDATE Department_Code=VALUES(Department_Code),Cache_TimeStamp=NOW()';
        } else if ($results && !isset($valuesArr['Course_ID'])) { //it's getting courses
            //Do things differently for neebo than the other schools.. Neebo gets courses and sections at one time, so we need to use a transaction to do that.
            if ($valuesArr['Bookstore_Type_Name'] != 'Neebo') {

                $query = 'INSERT INTO Courses_Cache (Department_ID, Course_Code, Course_Value) VALUES ';
                foreach ($results as $course) {
                    $query .= '(' . $valuesArr['Department_ID'] . ', "' . mysql_real_escape_string($course['Course_Code']) . '", "' . mysql_real_escape_string($course['Course_Value']) . '"),';
                }
                $query = substr(($query), 0, -1); //remove final comma
                $query .= ' ON DUPLICATE KEY UPDATE Course_Code=VALUES(Course_Code),Cache_TimeStamp=NOW()';
            } else {
                if ($results) {
                    //we need to have a transaction for adding them so we don't get partial additions..

                    mysql_query("START TRANSACTION");
                    mysql_query("SET @time = NOW()"); //we need to store NOW() at the getgo so everything has the same cache time.

                    $failed = false;
                    foreach ($results as $course) {
                        $neebo_query = 'INSERT INTO Courses_Cache (Department_ID, Course_Code, Course_Value) VALUES (' . $valuesArr['Department_ID'] . ', "' . mysql_real_escape_string($course['Course_Code']) . '", NULL) ON DUPLICATE KEY UPDATE Course_Code=VALUES(Course_Code),Cache_TimeStamp=NOW()'; //have to give it a different name than $query so we don't run the $query later
                        //we always insert NULL for course_value cus it's never sent.

                        if (!mysql_query($neebo_query)) {
                            trigger_error(mysql_error() . ' on course part of neebo course+class cache update query: ' . $neebo_query);
                            $failed = true;
                            mysql_query('ROLLBACK');
                            break;
                        } else {
                            $course_id = mysql_insert_id(); //get the course_id jst inserted
                            foreach ($course['Classes'] as $class) {
                                $neebo_query = 'INSERT INTO	Classes_Cache (Course_ID, Class_Code, Class_Value) VALUES (' . $course_id . ', "' . mysql_real_escape_string($class['Class_Code']) . '", "' . mysql_real_escape_string($class['Class_Value']) . '") ON DUPLICATE KEY UPDATE Class_Code=VALUES(Class_Code),Cache_TimeStamp=NOW()';
                                //DO:what about that there instructor? are we grabbing that?  want to get it seperatyely...
                                //make sure to set default value for when that wont work

                                if (!mysql_query($neebo_query)) {
                                    trigger_error(mysql_error() . ' on class part of neebo course+class cache update query: ' . $neebo_query);
                                    $failed = true;
                                    mysql_query('ROLLBACK');
                                    break 2;
                                }
                            }
                        }
                        //make sure it breaks out of both loops (use break 2) to skip to the loo
                    }

                    if (!$failed) { //it all went through
                        mysql_query('COMMIT');
                    }
                }
            }
        } else if ($results) { //it's getting classes aka sections.  by definition its not neebo which gets those when it gets courses.
            //we need to update this to store instructor.
            $query = 'INSERT INTO Classes_Cache (Course_ID, Class_Code, Class_Value, Instructor) VALUES ';
            foreach ($results as $class) {
                if (isset($class['Instructor'])) {
                    $class['Instructor'] = '"' . $class['Instructor'] . '"';
                } else {
                    $class['Instructor'] = 'NULL';
                }

                $query .= '(' . $valuesArr['Course_ID'] . ', "' . mysql_real_escape_string($class['Class_Code']) . '", "' . mysql_real_escape_string($class['Class_Value']) . '", ' . $class['Instructor'] . '),'; //Title capitalize Class_Code with ucwords() in case prof was in it
            }
            $query = substr(($query), 0, -1); //remove final comma
            $query .= ' ON DUPLICATE KEY UPDATE Class_Code=VALUES(Class_Code),Cache_TimeStamp=NOW()';
        }

        if (isset($query) && !mysql_query($query)) { //only applies to non-neebo.
            trigger_error(mysql_error() . ' on cache update query: ' . $query);
        }
    } else {
        trigger_error('Failed to query bookstore with ' . print_r($valuesArr, true), E_USER_WARNING);
    }
    if (isset($results)) {
        return $results;
    } else {
        return array();
    }
}

function update_class_items_from_bookstore($classValuesArr) { //$classValuesArr is an *array of arrays* of values to send to the bookstore (usually its from a $row result).  Expects Bookstore vars, Term_Value, Department_Value, Course_Value, and Class_Value.  This function updates the Class-Items and Items tables with the results.
    $resultsArray = array();
    $Items = array();

    $wait_times = array(FALSE, 250000, 400000); //double retries

    foreach ($classValuesArr as $valuesArr) {
        $results = array();
        for ($n = 0; $n < count($wait_times) && !$results; $n++) {
            if ($wait_times[$n]) {
                usleep($wait_times[$n]);
            }
            try {
                switch ($valuesArr['Bookstore_Type_Name']) {
                    case 'Barnes and Nobles':
                        $results = get_classes_and_items_from_bn($valuesArr);
                        break;
                    case 'CampusHub':
                        $results = get_classes_and_items_from_campushub($valuesArr);
                        break;
                    case 'MBS':
                        $results = get_classes_and_items_from_mbs($valuesArr);
                        break;
                    case 'Follett':
                        $results = get_classes_and_items_from_follett($valuesArr);
                        break;
                    case 'ePOS':
                        $results = get_classes_and_items_from_epos($valuesArr);
                        break;
                    case 'Neebo':
                        $results = get_classes_and_items_from_neebo($valuesArr);
                        break;
                    case 'eRATEX':
                        $results = get_classes_and_items_from_eratex($valuesArr);
                        break;
                    case 'Textbook Tech':
                        $results = get_classes_and_items_from_textbooktech($valuesArr);
                        break;
                    default:
                        throw new Exception("Unrecognized bookstore type for dropdowns: {$valuesArr['Bookstore_Type_Name']}");
                        break;
                    //These functions will return false or empty array on error or 0 $results...
                }
            } catch (Exception $e) {
                $results = false;
                trigger_error('Bookstore query problem: ' . $e->getMessage());
                break;
            }
            if ($results) {
                $Items = array();

                foreach ($results['items'] as $i => $item) {
                    //Set data source and format the item.. Also add it to $Items for later update.
                    //**make it so it ignores the ones with the bad titles..
                    $exclude = array('As Of Today,No Book Order Has Been Submitted,Pleas,'); #Note that this is their typo, not mine
                    $item = format_item($item);

                    if (!in_array(trim($item['Title']), $exclude) &&
                            (!isset($item['Necessity']) || !$item['Necessity'] || isNecessary($item['Necessity']) || (isset($item['ISBN']) && valid_book_ISBN13($item['ISBN'])))
                    ) { //we also require that its either (possibly) required or has an ISBN
                        $Items[] = $item;
                    }
                }

                $results['items'] = $Items;

                $resultsArray[] = $results;
            }
        }
    }

    if ($resultsArray) {
        if (!$Items || update_items_db($Items)) { //makes sure the update query works before proceeding
            if (!$conn = connect()) {
                trigger_error('DB connect failed');
            } else {
                $class_items_query = '';

                foreach ($resultsArray as $result) {
                    if (isset($result['items']) && $result['items']) {
                        /* we build a union select to get the Item_ID's for the books we just inserted into Items based on ISBN, or if ISBN isn't there, all the other fields.  The reason we select the info we already have is so we can easily match it with our data. */

                        $selectArray = array(); //cus we break it into a union
                        foreach ($result['items'] as $item) {
                            $New_Price = 'NULL';
                            $Used_Price = 'NULL';
                            $New_Rental_Price = 'NULL';
                            $Used_Rental_Price = 'NULL';
                            $Bookstore_Price = 'NULL';
                            $Necessity = 'NULL';
                            $Comments = 'NULL';

                            if (isset($item['Bookstore_Price'])) {
                                $Bookstore_Price = "'" . mysql_real_escape_string($item['Bookstore_Price']) . "'";
                            }
                            if (isset($item['New_Price'])) {
                                $New_Price = "'" . mysql_real_escape_string($item['New_Price']) . "'";
                            }
                            if (isset($item['Used_Price'])) {
                                $Used_Price = "'" . mysql_real_escape_string($item['Used_Price']) . "'";
                            }
                            if (isset($item['New_Rental_Price'])) {
                                $New_Rental_Price = "'" . mysql_real_escape_string($item['New_Rental_Price']) . "'";
                            }
                            if (isset($item['Used_Rental_Price'])) {
                                $Used_Rental_Price = "'" . mysql_real_escape_string($item['Used_Rental_Price']) . "'";
                            }

                            if (isset($item['Necessity'])) {
                                $Necessity = "'" . mysql_real_escape_string($item['Necessity']) . "'"; //title capitalize Necessity
                            }
                            if (isset($item['Comments'])) {
                                $Comments = "'" . mysql_real_escape_string($item['Comments']) . "'";
                            }

                            $select = 'SELECT Item_ID, ' .
                                    $Bookstore_Price . ' AS Bookstore_Price, ' .
                                    $New_Price . ' AS New_Price, ' .
                                    $Used_Price . ' AS Used_Price, ' .
                                    $New_Rental_Price . ' AS New_Rental_Price, ' .
                                    $Used_Rental_Price . ' AS Used_Rental_Price, ' .
                                    $Necessity . ' AS Necessity, ' .
                                    $Comments . ' AS Comments FROM Items WHERE ';
                            if (isset($item['ISBN']) && valid_ISBN13($item['ISBN'])) {
                                $select .= 'ISBN = ' . $item['ISBN'];
                            } else {
                                $Edition = "''";
                                $Authors = "''";
                                $Year = 0000;
                                $Publisher = "''";

                                $Title = "'" . mysql_real_escape_string($item['Title']) . "'";

                                if (isset($item['Edition'])) {
                                    $Edition = "'" . mysql_real_escape_string($item['Edition']) . "'";
                                }
                                if (isset($item['Authors'])) {
                                    $Authors = "'" . mysql_real_escape_string($item['Authors']) . "'"; //title capitalize authors
                                }
                                if (isset($item['Year'])) {
                                    $Year = $item['Year'];
                                }
                                if (isset($item['Publisher'])) {
                                    $Publisher = "'" . mysql_real_escape_string($item['Publisher']) . "'"; //Title capitalize  Publisher
                                }

                                $select .= 'ISBN IS NULL AND Title = ' . $Title . ' AND Edition = ' . $Edition . ' AND Authors = ' . $Authors . ' AND Year = ' . $Year . ' AND Publisher = ' . $Publisher;
                            }

                            $selectArray[] = $select;
                        }
                        $select_items_query = implode($selectArray, ' UNION ALL ');

                        if (!$select_result = mysql_query($select_items_query)) {
                            trigger_error(mysql_error() . ' with select items query ' . $select_items_query, E_USER_WARNING);
                        } else if (mysql_num_rows($select_result) == 0) {
                            trigger_error('0 rows on select items query ' . $select_items_query, E_USER_WARNING);
                        } else {
                            while ($row = mysql_fetch_assoc($select_result)) {
                                $Bookstore_Price = 'NULL';
                                $New_Price = 'NULL';
                                $Used_Price = 'NULL';
                                $New_Rental_Price = 'NULL';
                                $Used_Rental_Price = 'NULL';
                                $Necessity = 'NULL';
                                $Comments = 'NULL';

                                if ($row['Bookstore_Price']) {
                                    $Bookstore_Price = "'" . mysql_real_escape_string($row['Bookstore_Price']) . "'";
                                }
                                if ($row['New_Price']) {
                                    $New_Price = "'" . mysql_real_escape_string($row['New_Price']) . "'";
                                }
                                if ($row['Used_Price']) {
                                    $Used_Price = "'" . mysql_real_escape_string($row['Used_Price']) . "'";
                                }
                                if ($row['New_Rental_Price']) {
                                    $New_Rental_Price = "'" . mysql_real_escape_string($row['New_Rental_Price']) . "'";
                                }
                                if ($row['Used_Rental_Price']) {
                                    $Used_Rental_Price = "'" . mysql_real_escape_string($row['Used_Rental_Price']) . "'";
                                }

                                if ($row['Necessity']) {
                                    $Necessity = "'" . mysql_real_escape_string($row['Necessity']) . "'";
                                }
                                if ($row['Comments']) {
                                    $Comments = "'" . mysql_real_escape_string($row['Comments']) . "'";
                                }

                                $class_items_query .= '(' .
                                        $result['Class_ID'] . ', ' .
                                        $row['Item_ID'] . ', ' .
                                        $Bookstore_Price . ', ' .
                                        $New_Price . ', ' .
                                        $Used_Price . ', ' .
                                        $New_Rental_Price . ', ' .
                                        $Used_Rental_Price . ', ' .
                                        $Necessity . ', ' .
                                        $Comments .
                                        '),';
                            }
                        }
                    } else {
                        $Comments = 'NULL';
                        if (isset($item['Comments'])) { //there still might be some comments even if no items.
                            $Comments = "'" . mysql_real_escape_string($item['Comments']) . "'";
                        }
                        $class_items_query .= '(' . $result['Class_ID'] . ', NULL, NULL, NULL, NULL, NULL, NULL, NULL, ' . $Comments . '),';
                    }
                }

                $class_items_query = 'INSERT INTO Class_Items_Cache (Class_ID, Item_ID, Bookstore_Price, New_Price, Used_Price, New_Rental_Price, Used_Rental_Price, Necessity, Comments) VALUES ' . substr($class_items_query, 0, -1) . ' ON DUPLICATE KEY UPDATE Item_ID=VALUES(Item_ID),Bookstore_Price=VALUES(Bookstore_Price),New_Price=VALUES(New_Price),Used_Price=VALUES(Used_Price),New_Rental_Price=VALUES(New_Rental_Price),Used_Rental_Price=VALUES(Used_Rental_Price),Necessity=VALUES(Necessity),Comments=VALUES(Comments),Cache_TimeStamp=NOW()';

                if (!mysql_query($class_items_query)) {
                    trigger_error(mysql_error() . ' on class_items_query: ' . $class_items_query, E_USER_WARNING);
                }
            }
        }
    } else {
        trigger_error('Failed to query bookstore with ' . print_r($classValuesArr, true), E_USER_WARNING);
    }
}

function get_buybacks_from_bn(array $valuesArr) {
    $url = $valuesArr['Fetch_URL'] . 'webapp/wcs/stores/servlet/';

    $referer = $url . 'TBWizardView?catalogId=10001&storeId=' . $valuesArr['Store_Value'] . '&langId=-1';

    if (!isset($valuesArr['Class_ID'])) {
//make initialization request if they don't have a session yet...
        curl_request(array(CURLOPT_URL => $valuesArr['Storefront_URL'],
            CURLOPT_COOKIESESSION => true,
                /* CURLOPT_PROXY => PROXY_2,
                  CURLOPT_PROXYUSERPWD => PROXY_2_AUTH */
        ));

//pt 2 of initialization is requesting the textbook lookup page
        $options = array(CURLOPT_URL => $referer,
                /* CURLOPT_PROXY => PROXY_2,
                  CURLOPT_PROXYUSERPWD => PROXY_2_AUTH */
        );

        $response = curl_request($options);

        if (!$response) {
            throw new Exception('Failed to initialize the BN session with values ' . print_r($valuesArr, true));
        }
    }

    var_dump($valuesArr);
}

function get_buybacks_from_campushub(array $valuesArr) {
    $url = str_replace('buy_courselisting', 'sell_main', $valuesArr['Fetch_URL'])
            . '?txtISBN=' . $valuesArr['isbn'] . '&rdoCondition=NEW&PageAction=ADD';

    $options = array(
        CURLOPT_URL => $url
    );
    $response = curl_request($options); //query the main page to pick up the cookies

    if (!$response) {
        throw new Exception('Unable to fetch buyback from Campus Hub with values ' . print_r($valuesArr, true));
    }

    $doc = new DOMDocument();
    @$doc->loadHTML($response); //because their HTML is imperfect

    $finder = new DomXPath($doc);

    $noBuybackResults = $finder->query('//div[@id="flash"]');
    if ($noBuybackResults->length != 0) {
        $returnArray = array(
            'buying' => false,
            'buyback_status' => 'Not Buying'
        );
        return $returnArray;
    }
//sell_main.asp?
    $priceResults = $finder->query('//span[@class="book-price"]');
    if ($priceResults->length != 0) {
        preg_match('/\$([0-9\.]+)/', $priceResults->item(0)->nodeValue, $matches);
        if (count($matches) > 1) {
            $returnArray = array(
                'buying' => true,
                'price' => $matches[1],
                'reason' => 'Buying',
                'link' => $url
            );
        } else {
            $returnArray = array(
                'buying' => false,
                'reason' => 'ERROR: Unable to find price'
            );
        }

        return $returnArray;
    } else {
        $returnArray = array(
            'buying' => false,
            'reason' => 'ERROR: Parsing error'
        );
        return $returnArray;
    }
}

function get_buybacks_from_epos(array $valuesArr) {
    $url = $valuesArr['Fetch_URL'] . '?';

    if ($valuesArr['Store_Value']) {
        $url .= 'store=' . $valuesArr['Store_Value'] . '&';
    }

    $user_agent = urlencode(random_user_agent());
//width=100%25&store=411&host=24.23.30.148&design=411
//&cellspacing=0&cellpadding=5&border=0
//&Search.x=34&Search.y=14&Search=Search
    $url .= 'form=shared3%2Ftextbooks%2Fbuyback%2Fbuyback_isbn.html&agent=' . $user_agent
            . '&SKU=' . $valuesArr['isbn'];

    echo $url;
}

function get_buybacks_from_follett(array $valuesArr) {
    $url = $valuesArr['Fetch_URL'] . 'webapp/wcs/stores/servlet/';

    $referer = $url . 'BuybackMaterialsView?' .
            http_build_query(array(
                'storeId' => $valuesArr['Store_Value'],
                'schoolStoreId' => $valuesArr['Store_Value']
    ));

    $options = array(
        CURLOPT_URL => $referer,
            /* CURLOPT_HTTPPROXYTUNNEL => true,
              CURLOPT_PROXY => PROXY_1,
              CURLOPT_PROXYUSERPWD => PROXY_1_AUTH */
    );
    $response = curl_request($options); //query the main page to pick up the cookies

    if (!$response) {
        throw new Exception('Unable to fetch Follett Storefront for session with values ' . print_r($valuesArr, true));
    }

// need to get the catalogId, is it always 10001?

    $doc = new DOMDocument();
    @$doc->loadHTML($response); //because their HTML is imperfect

    $finder = new DomXPath($doc);

    $catalogIdResults = $finder->query('//input[@name="catalogId"]');
    if ($catalogIdResults->length == 0) {
        $returnArray = array(
            'buying' => false,
            'reason' => 'ERROR: Catalog ID parsing error'
        );
        return $returnArray;
    }
    $catalogId = $catalogIdResults->item(0)->attributes->getNamedItem('value')->nodeValue;

// now request the buyback
    $options = array(
        CURLOPT_URL => $url . 'BuybackSearch',
        CURLOPT_REFERER => $referer,
        CURLOPT_POSTFIELDS => array(
            'catalogId' => $catalogId,
            'schoolStoreId' => $valuesArr['Store_Value'],
            'isbn' => $valuesArr['isbn']
        )
            /* CURLOPT_HTTPPROXYTUNNEL => true,
              CURLOPT_PROXY => PROXY_1,
              CURLOPT_PROXYUSERPWD => PROXY_1_AUTH */
    );
    $response = curl_request($options); //query the main page to pick up the cookies

    if ($response) {
        $doc = new DOMDocument();
        @$doc->loadHTML($response); //because their HTML is imperfect

        $finder = new DomXPath($doc);

        $noBuybackResults = $finder->query('//td[@id="msg_no_buyback_available"]');
        if ($noBuybackResults->length != 0) {
            $returnArray = array(
                'buying' => false,
                'buyback_status' => 'Not Buying'
            );
            return $returnArray;
        }

        $isbnNotFoundResults = $finder->query('//div[@id="buyback_div_isbnnotfound"]');
        if ($isbnNotFoundResults->length != 0) {
            $returnArray = array(
                'buying' => false,
                'reason' => 'ISBN Not Found'
            );
            return $returnArray;
        }

        $priceResults = $finder->query('//form[@name="addForm"]//strong');
        if ($priceResults->length != 0) {
            preg_match('/\$([0-9\.]+)/', $priceResults->item(0)->nodeValue, $matches);
            if (count($matches) > 1) {
                $returnArray = array(
                    'buying' => true,
                    'price' => $matches[1],
                    'reason' => 'Buying',
                    'link' => $referer
                );
            } else {
                $returnArray = array(
                    'buying' => false,
                    'reason' => 'ERROR: Unable to find price'
                );
            }

            return $returnArray;
        } else {
            $returnArray = array(
                'buying' => false,
                'reason' => 'ERROR: Parsing error'
            );
            return $returnArray;
        }
    } else {
        throw new Exception("No response with values " . print_r($valuesArr, true));
    }
}

function get_buybacks_from_mbs(array $valuesArr) {
    $failSafe = 0;

    $doc = new DOMDocument();

    $useragent = 'Mozilla/5.0 (iPhone; U; CPU iPhone OS 3_0 like Mac OS X; en-us) AppleWebKit/528.18 (KHTML, like Gecko) Version/4.0 Mobile/7A341 Safari/528.16'; //IPhone useragent, because we fetch from the easy to scrape mobile version.
    $mbs_url = $valuesArr['Fetch_URL'] . 'buyback_m.aspx';

    do {
        if (!isset($sessionStarted) || !$sessionStarted) {
            //initial terms request to establish a session
            $options = array(CURLOPT_URL => $mbs_url,
                CURLOPT_USERAGENT => $useragent,
                CURLOPT_COOKIESESSION => true);
        } else {
            $options = array(CURLOPT_URL => $mbs_url,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => '__VIEWSTATE=' . urlencode($mbs_viewstate) . '&__EVENTTARGET=&__EVENTARGUMENT=&' . $mbs_isbn_name . '=' . $valuesArr['isbn'] . '&cmdSearch=Search',
                CURLOPT_USERAGENT => $useragent);
        }
        //time to make the response
        $response = curl_request($options);

        if (!$response) {
            throw new Exception('No response with values ' . print_r($valuesArr, true));
        } else {
            @$doc->loadHTML($response); //because their HTML is malformed.

            if (!isset($sessionStarted) || !$sessionStarted) { //not a class-item response
                $form_tag = $doc->getElementsByTagName('form');
                if ($form_tag->length == 0) {
                    throw new Exception('No form in response with values ' . print_r($valuesArr, true));
                } else {
                    $form = $form_tag->item(0);
                    $input_tags = $doc->getElementsByTagName('input');
                    if ($input_tags->length == 0) {
                        throw new Exception('No input in response with values ' . print_r($valuesArr, true));
                    } else { //form and input tags are there as they're supposed to be
                        $input = $input_tags->item(0);
                        //update the state session stuff..
                        $mbs_url = $valuesArr['Fetch_URL'] . $form->getAttribute('action');
                        $mbs_viewstate = $input->getAttribute('value');

                        for ($i = 1; $i < $input_tags->length; $i++) {
                            $tag = $input_tags->item($i);
                            if ($tag->getAttribute('type') == 'text') {
                                $mbs_isbn_name = $tag->getAttribute('name');
                                break;
                            }
                        }
                    }
                }
                $sessionStarted = true;
            } else { //isbn response to handle
                $table_tags = $doc->getElementsByTagName('table');

                if ($table_tags->length == 1) { // table means they are buying, no table means they are not
                    // first row, second column
                    $td_tag = $table_tags->item(0)
                                    ->getElementsByTagName('tr')->item(0)
                                    ->getElementsByTagName('td')->item(1);

                    for ($i = 0; $i < $td_tag->childNodes->length; $i++) {
                        $child = $td_tag->childNodes->item($i);
                        if ($child->nodeType == 3) {
                            $value = trim($child->nodeValue);
                            if (strpos($value, 'Estimated Buyback Price: $') === 0) {
                                $price = str_replace('Estimated Buyback Price: $', '', $value);
                                break;
                            }
                        }
                    }

                    if (isset($price) && is_numeric($price)) {
                        $returnArray = array(
                            'buying' => true,
                            'price' => doubleval($price),
                            'reason' => 'Buying',
                            'link' => $valuesArr['Storefront_URL']
                        );
                    } else {
                        $returnArray = array(
                            'buying' => false,
                            'reason' => 'ERROR: Parse Error'
                        );
                    }
                } else {
                    $returnArray = array(
                        'buying' => false,
                        'reason' => 'Not Buying'
                    );
                }
            }
        }
    } while (!isset($returnArray));

    return $returnArray;
}

function get_buybacks_from_neebo(array $valuesArr) {
    $url = $valuesArr['Fetch_URL'] . '/SellBack/LookupIsbns';
    $referer = $valuesArr['Fetch_URL'] . '/Sell-Textbooks';

    $options = array(
        CURLOPT_URL => $url,
        CURLOPT_REFERER => $referer,
        CURLOPT_POSTFIELDS => array(
            'isbns' => $valuesArr['isbn'],
            'slug' => $valuesArr['Store_Value']
        )
    );
    $response = curl_request($options); //query the main page to pick up the cookies

    if (!$response) {
        throw new Exception('Unable to fetch Neebo buyback with values ' . print_r($valuesArr, true));
    }

    $data = json_decode($response, true);
    if (!$data) {
        $returnArray = array(
            'buying' => false,
            'buyback_status' => 'ERROR: Unable to parse JSON'
        );
    }
    if ($data[0] && $data[0]['PriceQuote']) {
        $Items = array(
            array(
                'ISBN' => $data[0]['Isbn'],
                'Title' => $data[0]['Name'],
                'Edition' => $data[0]['Edition'],
                'Authors' => $data[0]['Author']
            )
        );
        update_items_db($Items);

        if ($data[0]['PriceQuote']['PriceQuote'] > 0) {
            $returnArray = array(
                'buying' => true,
                'price' => $data[0]['PriceQuote']['PriceQuote'],
                'reason' => 'Buying',
                'link' => $referer
            );
        } else {
            $returnArray = array(
                'buying' => false,
                'buyback_status' => 'Not Buying'
            );
        }
    } else {
        $returnArray = array(
            'buying' => false,
            'buyback_status' => 'ERROR: JSON format not as expected'
        );
    }

    return $returnArray;
}

function update_buyback_items_from_bookstore(array $valuesArr) {
    $cacheResult = false;

    try {
// check the cache
        $select = 'SELECT *,Cache_TimeStamp BETWEEN NOW() - INTERVAL 1 WEEK AND NOW() as `CacheValid` FROM Buyback_Cache WHERE Campus_ID = ' .
                $valuesArr['Campus_ID'] . ' AND ISBN = \'' .
                mysql_real_escape_string($valuesArr['isbn']) . '\'';

        if (($result = mysql_query($select)) && mysql_num_rows($result) != 0) {
            $row = mysql_fetch_assoc($result);
            $cacheResult = array(
                'buying' => (bool) $row['buying'],
                'price' => $row['price'],
                'reason' => $row['reason'],
                'link' => $row['link']
            );
            if ($row['CacheValid']) {
                return $cacheResult;
            }
        }

        switch ($valuesArr['Bookstore_Type_Name']) {
            case 'Barnes and Nobles':
                //$results = get_buybacks_from_bn($valuesArr);
                $results = array();
                break;
            case 'CampusHub':
                $results = get_buybacks_from_campushub($valuesArr);
                break;
            case 'MBS':
                $results = get_buybacks_from_mbs($valuesArr);
                break;
            case 'Follett':
                $results = get_buybacks_from_follett($valuesArr);
                break;
            case 'ePOS':
                //$results = get_buybacks_from_epos($valuesArr);
                $results = array();
                break;
            case 'Neebo':
                $results = get_buybacks_from_neebo($valuesArr);
                break;
            default:
                throw new Exception("Unrecognized bookstore type for buyback: {$valuesArr['Bookstore_Type_Name']}");
                break;
            //These functions will return false or empty array on error or 0 $results...
        }

// insert into cache
        $query = "INSERT INTO `Buyback_Cache`
(`Campus_ID`, `ISBN`, `buying`, `price`, `reason`, `link`)
VALUES ({$valuesArr['Campus_ID']},
'{$valuesArr['isbn']}',
" . intval($results['buying']) . ",
" . ((isset($results['price']) && $results['price']) ? $results['price'] : 'NULL') . ",
'" . mysql_real_escape_string(isset($results['reason']) ? $results['reason'] : '') . "',
'" . mysql_real_escape_string(isset($results['link']) ? $results['link'] : '') . "')
ON DUPLICATE KEY UPDATE `buying`=VALUES(buying), price=VALUES(price), reason=VALUES(reason), link=VALUES(link)";

        mysql_query($query);
    } catch (Exception $e) {
        $results = false;
        if ($cacheResult) { // fall back to cached results if they are available
            $results = $cacheResult;
        }
        trigger_error('Bookstore query problem: ' . $e->getMessage());
    }

    return $results;
}

