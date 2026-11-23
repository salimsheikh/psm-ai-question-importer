<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PSM_Languages {
	public static function languages(): array {
		return array(
			''           => __( 'Plain text', 'programming-skills-master' ),
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
