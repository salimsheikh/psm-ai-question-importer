<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PSM_Languages {
	public static function languages(): array {
		return array(
			''           => __( 'Plain text', 'psm-ai-importer' ),
			'php'        => 'PHP',
			'csharp'     => 'C#',
			'java'       => 'Java',
			'python'     => 'Python',
			'javascript' => 'JavaScript',
			'typescript' => 'TypeScript',
			'sql'        => 'SQL',
			'html'       => 'HTML',
			'css'        => 'CSS',
			'json'       => 'JSON',
			'bash'       => 'Bash',
		);
	}
}
