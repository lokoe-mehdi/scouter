<?php

use App\Core\Page;

describe('Page - Type Detection', function () {

    it('detects HTML content type', function () {
        $html = '<!DOCTYPE html><html><head><title>Test</title></head><body><h1>Hello</h1></body></html>';
        $url = 'https://example.com/page';
        $headers = (object)[
            'http_code' => 200,
            'content_type' => 'text/html; charset=utf-8',
            'total_time' => 0.5,
            'redirect_url' => '',
            'size_download' => 1024
        ];
        
        $page = new Page($url, $headers, $html, ['example.com'], [
            'xPathExtractors' => [],
            'regexExtractors' => []
        ]);
        $result = $page->getPage();
        
        expect($result->is_html)->toBeTrue();
    });

    it('detects PDF by extension', function () {
        $url = 'https://example.com/document.pdf';
        $headers = (object)[
            'http_code' => 200,
            'content_type' => 'application/pdf',
            'total_time' => 0.5,
            'redirect_url' => '',
            'size_download' => 2048
        ];
        
        // Simuler un contenu PDF (magic bytes)
        $pdfContent = "%PDF-1.4 fake pdf content";
        
        $page = new Page($url, $headers, $pdfContent, ['example.com'], [
            'xPathExtractors' => [],
            'regexExtractors' => []
        ]);
        $result = $page->getPage();
        
        expect($result->is_html)->toBeFalse();
    });

    it('detects images by content type', function () {
        $url = 'https://example.com/image.jpg';
        $headers = (object)[
            'http_code' => 200,
            'content_type' => 'image/jpeg',
            'total_time' => 0.5,
            'redirect_url' => '',
            'size_download' => 5000
        ];
        
        // Simuler un contenu image (magic bytes JPEG)
        $imageContent = "\xFF\xD8\xFF fake image content";
        
        $page = new Page($url, $headers, $imageContent, ['example.com'], [
            'xPathExtractors' => [],
            'regexExtractors' => []
        ]);
        $result = $page->getPage();
        
        expect($result->is_html)->toBeFalse();
    });

    it('detects binary content by printable ratio', function () {
        $url = 'https://example.com/file.bin';
        $headers = (object)[
            'http_code' => 200,
            'content_type' => 'application/octet-stream',
            'total_time' => 0.5,
            'redirect_url' => '',
            'size_download' => 600
        ];
        
        // Contenu binaire avec beaucoup de caractères non-imprimables
        $binaryContent = str_repeat("\x00\x01\x02\x03\x04\x05", 100);
        
        $page = new Page($url, $headers, $binaryContent, ['example.com'], [
            'xPathExtractors' => [],
            'regexExtractors' => []
        ]);
        $result = $page->getPage();
        
        expect($result->is_html)->toBeFalse();
    });

});
