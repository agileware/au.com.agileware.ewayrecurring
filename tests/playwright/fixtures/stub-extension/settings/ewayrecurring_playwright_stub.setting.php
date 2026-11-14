<?php

/**
 * The stub's queue of scripted eWAY responses and its log of what it was asked.
 * Written by the Playwright tests, read and written by the stub.
 */
return [
  'ewayrecurring_stub_queue' => [
    'name' => 'ewayrecurring_stub_queue',
    'type' => 'Array',
    'default' => [],
    'add' => '5.51',
    'is_domain' => 1,
    'is_contact' => 0,
    'title' => 'eWAY stub: scripted responses',
    'description' => 'Test support only.',
  ],
  'ewayrecurring_stub_log' => [
    'name' => 'ewayrecurring_stub_log',
    'type' => 'Array',
    'default' => [],
    'add' => '5.51',
    'is_domain' => 1,
    'is_contact' => 0,
    'title' => 'eWAY stub: requests received',
    'description' => 'Test support only.',
  ],
];
