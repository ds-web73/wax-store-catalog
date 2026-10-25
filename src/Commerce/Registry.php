<?php
/**
 * Commerce providers registry.
 *
 * @package WaxStore\Catalog
 */

namespace WaxStore\Catalog\Commerce;

use WaxStore\Catalog\Commerce\Providers\ShowcaseProvider;

defined( 'ABSPATH' ) || exit;

/**
 * Collects providers and resolves the active one.
 */
final class Registry {

	const OPTION = 'waxsc_commerce_provider';

	/**
	 * Supported providers, keyed by ID.
	 *
	 * @var ProviderInterface[]|null
	 */
	private ?array $providers = null;

	/**
	 * Active provider.
	 *
	 * @var ProviderInterface|null
	 */
	private ?ProviderInterface $active = null;

	/**
	 * Returns supported providers. The showcase provider is always available.
	 *
	 * @return ProviderInterface[]
	 */
	public function get_providers(): array {
		if ( null === $this->providers ) {
			/**
			 * Filters the available commerce providers.
			 *
			 * @param ProviderInterface[] $providers Provider instances.
			 */
			$candidates = apply_filters( 'waxsc_commerce_providers', array( new ShowcaseProvider() ) );

			$this->providers = array();

			foreach ( (array) $candidates as $provider ) {
				if ( $provider instanceof ProviderInterface && $provider->is_supported() ) {
					$this->providers[ $provider->get_id() ] = $provider;
				}
			}

			if ( ! isset( $this->providers[ ShowcaseProvider::ID ] ) ) {
				$this->providers[ ShowcaseProvider::ID ] = new ShowcaseProvider();
			}
		}

		return $this->providers;
	}

	/**
	 * Returns the active provider, falling back to showcase when the
	 * selected one is missing (e.g. WooCommerce deactivated).
	 */
	public function get_active(): ProviderInterface {
		if ( null === $this->active ) {
			$providers    = $this->get_providers();
			$id           = (string) get_option( self::OPTION, ShowcaseProvider::ID );
			$this->active = $providers[ $id ] ?? $providers[ ShowcaseProvider::ID ];
		}

		return $this->active;
	}

	/**
	 * Post type holding the catalog for the active provider.
	 */
	public function get_catalog_post_type(): string {
		return $this->get_active()->get_catalog_post_type();
	}
}
