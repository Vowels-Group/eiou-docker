<?php
/**
 * Example test file to demonstrate SimpleTest framework
 */

require_once __DIR__ . '/SimpleTest.php';

// Register tests
SimpleTest::test('Basic assertions work', function() {
    SimpleTest::assertTrue(true, "True should be true");
    SimpleTest::assertEquals(2 + 2, 4, "Basic math should work");
    SimpleTest::assertNotNull("test", "String should not be null");
});

SimpleTest::test('String operations work', function() {
    $testString = "Hello, World!";
    SimpleTest::assertStringContains("World", $testString, "Should contain 'World'");
    SimpleTest::assertStringNotContains("Goodbye", $testString, "Should not contain 'Goodbye'");
});

SimpleTest::test('Array operations work', function() {
    $testArray = ['key1' => 'value1', 'key2' => 'value2'];
    SimpleTest::assertArrayHasKey('key1', $testArray, "Array should have 'key1'");
    SimpleTest::assertEquals($testArray['key1'], 'value1', "key1 should equal 'value1'");
});

SimpleTest::test('Mock functions work', function() {
    $mockPDO = SimpleTest::mockFunction('createPDOConnection', 'mock_pdo');
    $result = $mockPDO();
    SimpleTest::assertEquals($result, 'mock_pdo', "Mock should return expected value");
    SimpleTest::assertTrue($mockPDO->wasCalled(), "Mock should track calls");
});

// Run the tests
SimpleTest::run();