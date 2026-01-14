<?php
/**
 * Book API Integration
 * Handles Google Books API and Open Library API
 */

class BookAPI {
    private $google_api_key = ''; // Add your Google Books API key here (optional)
    
    /**
     * Search book by ISBN using Google Books API
     */
    public function searchByISBN_Google($isbn) {
        $isbn = preg_replace('/[^0-9X]/', '', $isbn);
        
        $url = "https://www.googleapis.com/books/v1/volumes?q=isbn:" . urlencode($isbn);
        
        if (!empty($this->google_api_key)) {
            $url .= "&key=" . $this->google_api_key;
        }
        
        $response = $this->makeRequest($url);
        
        if ($response && isset($response['items'][0])) {
            return $this->parseGoogleBook($response['items'][0]);
        }
        
        return null;
    }
    
    /**
     * Search book by title using Google Books API
     */
    public function searchByTitle_Google($title, $author = '') {
        $query = "intitle:" . urlencode($title);
        if (!empty($author)) {
            $query .= "+inauthor:" . urlencode($author);
        }
        
        $url = "https://www.googleapis.com/books/v1/volumes?q=" . $query;
        
        if (!empty($this->google_api_key)) {
            $url .= "&key=" . $this->google_api_key;
        }
        
        $response = $this->makeRequest($url);
        
        if ($response && isset($response['items'])) {
            $books = array();
            foreach ($response['items'] as $item) {
                $books[] = $this->parseGoogleBook($item);
            }
            return $books;
        }
        
        return array();
    }
    
    /**
     * Search book by ISBN using Open Library API
     */
    public function searchByISBN_OpenLibrary($isbn) {
        $isbn = preg_replace('/[^0-9X]/', '', $isbn);
        
        $url = "https://openlibrary.org/api/books?bibkeys=ISBN:" . $isbn . "&format=json&jscmd=data";
        
        $response = $this->makeRequest($url);
        
        if ($response && isset($response["ISBN:" . $isbn])) {
            return $this->parseOpenLibraryBook($response["ISBN:" . $isbn], $isbn);
        }
        
        return null;
    }
    
    /**
     * Search book by title using Open Library API
     */
    public function searchByTitle_OpenLibrary($title, $author = '') {
        $query = urlencode($title);
        if (!empty($author)) {
            $query .= "+" . urlencode($author);
        }
        
        $url = "https://openlibrary.org/search.json?q=" . $query . "&limit=5";
        
        $response = $this->makeRequest($url);
        
        if ($response && isset($response['docs'])) {
            $books = array();
            foreach ($response['docs'] as $doc) {
                $books[] = $this->parseOpenLibrarySearch($doc);
            }
            return $books;
        }
        
        return array();
    }
    
    /**
     * Universal search - tries both APIs
     */
    public function searchBook($isbn = '', $title = '', $author = '') {
        $result = array(
            'success' => false,
            'data' => null,
            'source' => null
        );
        
        // Try ISBN first if provided
        if (!empty($isbn)) {
            // Try Google Books first
            $data = $this->searchByISBN_Google($isbn);
            if ($data) {
                $result['success'] = true;
                $result['data'] = $data;
                $result['source'] = 'Google Books';
                return $result;
            }
            
            // Fallback to Open Library
            $data = $this->searchByISBN_OpenLibrary($isbn);
            if ($data) {
                $result['success'] = true;
                $result['data'] = $data;
                $result['source'] = 'Open Library';
                return $result;
            }
        }
        
        // Try title search if ISBN failed or not provided
        if (!empty($title)) {
            // Try Google Books
            $books = $this->searchByTitle_Google($title, $author);
            if (!empty($books)) {
                $result['success'] = true;
                $result['data'] = $books[0];
                $result['source'] = 'Google Books';
                return $result;
            }
            
            // Fallback to Open Library
            $books = $this->searchByTitle_OpenLibrary($title, $author);
            if (!empty($books)) {
                $result['success'] = true;
                $result['data'] = $books[0];
                $result['source'] = 'Open Library';
                return $result;
            }
        }
        
        return $result;
    }
    
    /**
     * Parse Google Books API response
     */
    private function parseGoogleBook($item) {
        $volumeInfo = isset($item['volumeInfo']) ? $item['volumeInfo'] : array();
        
        $isbn = '';
        if (isset($volumeInfo['industryIdentifiers'])) {
            foreach ($volumeInfo['industryIdentifiers'] as $identifier) {
                if ($identifier['type'] === 'ISBN_13' || $identifier['type'] === 'ISBN_10') {
                    $isbn = $identifier['identifier'];
                    break;
                }
            }
        }
        
        return array(
            'title' => isset($volumeInfo['title']) ? $volumeInfo['title'] : '',
            'author' => isset($volumeInfo['authors']) ? implode(', ', $volumeInfo['authors']) : '',
            'isbn' => $isbn,
            'published_year' => isset($volumeInfo['publishedDate']) ? substr($volumeInfo['publishedDate'], 0, 4) : '',
            'category' => isset($volumeInfo['categories'][0]) ? $volumeInfo['categories'][0] : '',
            'description' => isset($volumeInfo['description']) ? $volumeInfo['description'] : '',
            'page_count' => isset($volumeInfo['pageCount']) ? $volumeInfo['pageCount'] : '',
            'publisher' => isset($volumeInfo['publisher']) ? $volumeInfo['publisher'] : '',
            'thumbnail' => isset($volumeInfo['imageLinks']['thumbnail']) ? $volumeInfo['imageLinks']['thumbnail'] : '',
            'cover_image' => isset($volumeInfo['imageLinks']['large']) ? $volumeInfo['imageLinks']['large'] : (isset($volumeInfo['imageLinks']['medium']) ? $volumeInfo['imageLinks']['medium'] : ''),
        );
    }
    
    /**
     * Parse Open Library API response (single book)
     */
    private function parseOpenLibraryBook($data, $isbn) {
        $authors = array();
        if (isset($data['authors'])) {
            foreach ($data['authors'] as $author) {
                $authors[] = $author['name'];
            }
        }
        
        return array(
            'title' => isset($data['title']) ? $data['title'] : '',
            'author' => implode(', ', $authors),
            'isbn' => $isbn,
            'published_year' => isset($data['publish_date']) ? substr($data['publish_date'], -4) : '',
            'category' => isset($data['subjects'][0]['name']) ? $data['subjects'][0]['name'] : '',
            'description' => '',
            'page_count' => isset($data['number_of_pages']) ? $data['number_of_pages'] : '',
            'publisher' => isset($data['publishers'][0]['name']) ? $data['publishers'][0]['name'] : '',
            'thumbnail' => isset($data['cover']['small']) ? $data['cover']['small'] : '',
            'cover_image' => isset($data['cover']['large']) ? $data['cover']['large'] : (isset($data['cover']['medium']) ? $data['cover']['medium'] : ''),
        );
    }
    
    /**
     * Parse Open Library search results
     */
    private function parseOpenLibrarySearch($doc) {
        $isbn = '';
        if (isset($doc['isbn'][0])) {
            $isbn = $doc['isbn'][0];
        }
        
        $coverUrl = '';
        if (isset($doc['cover_i'])) {
            $coverUrl = "https://covers.openlibrary.org/b/id/" . $doc['cover_i'] . "-L.jpg";
        }
        
        return array(
            'title' => isset($doc['title']) ? $doc['title'] : '',
            'author' => isset($doc['author_name'][0]) ? $doc['author_name'][0] : '',
            'isbn' => $isbn,
            'published_year' => isset($doc['first_publish_year']) ? $doc['first_publish_year'] : '',
            'category' => isset($doc['subject'][0]) ? $doc['subject'][0] : '',
            'description' => '',
            'page_count' => isset($doc['number_of_pages_median']) ? $doc['number_of_pages_median'] : '',
            'publisher' => isset($doc['publisher'][0]) ? $doc['publisher'][0] : '',
            'thumbnail' => $coverUrl,
            'cover_image' => $coverUrl,
        );
    }
    
    /**
     * Make HTTP request
     */
    private function makeRequest($url) {
        // Check if cURL is available
        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Library Management System/1.0');
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode === 200 && $response) {
                return json_decode($response, true);
            }
        } else {
            // Fallback to file_get_contents if cURL not available
            $context = stream_context_create(array(
                'http' => array(
                    'timeout' => 10,
                    'user_agent' => 'Library Management System/1.0'
                )
            ));
            
            $response = @file_get_contents($url, false, $context);
            if ($response) {
                return json_decode($response, true);
            }
        }
        
        return null;
    }
}
?>