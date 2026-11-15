<?php
/**
 * Publisher interruption and shared CLI status regression tests. Run with
 * php directly or wp eval-file. Child processes use real Build/Slots code
 * with stubbed WordPress calls, only scratch directories, and no network.
 */

$src    = dirname( __DIR__ ) . '/src';
$worker = __DIR__ . '/fixtures/build-worker.php';
$tmp    = sys_get_temp_dir() . '/bds-lifecycle-' . bin2hex( random_bytes( 4 ) );
$bad    = 0;
$count  = 0;
$check  = function ( string $name, bool $ok ) use ( &$bad, &$count ) {
	++$count;
	$bad += $ok ? 0 : 1;
	echo ( $ok ? 'ok    ' : 'FAIL  ' ) . $name . "\n";
};
$run = function ( string $mode ) use ( $worker, $tmp, $src ): array {
	$p = proc_open( array( PHP_BINARY, $worker, $mode, $tmp, $src ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
	fclose( $pipes[0] );
	$out = stream_get_contents( $pipes[1] );
	$err = stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	return array( proc_close( $p ), $out, $err );
};
$seed = function () use ( $tmp ): void {
	if ( is_link( "$tmp/static" ) ) {
		unlink( "$tmp/static" );
	}
	symlink( 'static-builds/a', "$tmp/static" );
	foreach ( array( 'a', 'b' ) as $s ) {
		file_put_contents( "$tmp/static-builds/$s/index.html", 'Earlier complete build' );
		file_put_contents( "$tmp/static-builds/state/slot-$s.json", json_encode( array(
			'id' => "old-$s", 'slot' => $s, 'result' => 'a' === $s ? 'published' : 'validated', 'started_ts' => 100,
		) ) );
	}
};

try {
	foreach ( array( 'a', 'b', 'state' ) as $s ) {
		mkdir( "$tmp/static-builds/$s", 0755, true );
	}
	$seed();
	[ $exit, $out ] = $run( 'publish' );
	$check( 'validated candidate can still publish', 0 === $exit && 'published' === ( json_decode( $out, true )['result'] ?? null ) && 'static-builds/b' === readlink( "$tmp/static" ) );

	foreach ( array( 'interrupt-empty', 'interrupt-fetch' ) as $mode ) {
		$seed();
		[ $exit ] = $run( $mode );
		$report = json_decode( file_get_contents( "$tmp/static-builds/state/slot-b.json" ), true );
		$check( "$mode: process exited abruptly", 88 === $exit );
		$check( "$mode: old candidate invalidated", 'running' === $report['result'] && 'old-b' !== $report['id'] );
		if ( 'interrupt-fetch' === $mode ) {
			$check( 'partial replacement includes index.html', str_contains( file_get_contents( "$tmp/static-builds/b/index.html" ), 'Partial, unvalidated' ) );
		}
		[ $exit, , $err ] = $run( 'publish' );
		$check( "$mode: publish refuses the incomplete candidate", 1 === $exit && str_contains( $err, 'no validated, unpublished build' ) );
		$check( "$mode: live site untouched", 'static-builds/a' === readlink( "$tmp/static" ) && 'Earlier complete build' === file_get_contents( "$tmp/static/index.html" ) );
	}

	$seed();
	[ $exit, $out ] = $run( 'status-json' );
	$status = json_decode( $out, true );
	$check( 'JSON status includes the live build and pending change', 0 === $exit && 'old-a' === $status['live_r']['id'] && true === $status['pending'] && 'fixture edit' === $status['change']['what'] );
	$check( 'JSON status includes the queue and worker error', 600 === $status['queued'] && 'fixture error' === $status['error']['message'] );
} finally {
	if ( is_dir( $tmp ) ) {
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $tmp, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $it as $f ) {
			$f->isDir() && ! $f->isLink() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() );
		}
		rmdir( $tmp );
	}
}
echo ( $count - $bad ) . " passed, $bad failed\n";
if ( $bad ) {
	exit( 1 );
}
