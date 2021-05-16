<?php
declare( strict_types = 1 );

/**
 * @author Niklas Laxström
 * @license GPL-2.0-or-later
 * @since 2021.05
 */
class GroupsAid extends TranslationAid {
	public function getData(): array {
		return [ '**' => 'group' ] + $this->handle->getGroupIds();
	}
}
