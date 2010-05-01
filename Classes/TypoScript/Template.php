<?php
declare(ENCODING = 'utf-8');
namespace F3\TYPO3\TypoScript;

/*                                                                        *
 * This script belongs to the FLOW3 package "TYPO3".                      *
 *                                                                        *
 * It is free software; you can redistribute it and/or modify it under    *
 * the terms of the GNU General Public License as published by the Free   *
 * Software Foundation, either version 3 of the License, or (at your      *
 * option) any later version.                                             *
 *                                                                        *
 * This script is distributed in the hope that it will be useful, but     *
 * WITHOUT ANY WARRANTY; without even the implied warranty of MERCHAN-    *
 * TABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General      *
 * Public License for more details.                                       *
 *                                                                        *
 * You should have received a copy of the GNU General Public License      *
 * along with the script.                                                 *
 * If not, see http://www.gnu.org/licenses/gpl.html                       *
 *                                                                        *
 * The TYPO3 project - inspiring people to share!                         *
 *                                                                        */

/**
 * A TypoScript Template object
 *
 * @version $Id$
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 3 or later
 * @scope prototype
 */
class Template extends \F3\TypoScript\AbstractObject {

	/**
	 * @inject
	 * @var \F3\Fluid\Core\Parser\TemplateParser
	 */
	protected $templateParser;

	/**
	 * @inject
	 * @var \F3\FLOW3\Object\ObjectManagerInterface
	 */
	protected $objectManager;

	/**
	 *
	 * @var mixed
	 */
	protected $source;

	/**
	 *
	 * @var array
	 */
	protected $variables = array();

	/**
	 * Sets the Fluid template source.
	 * 
	 * Valid sources are:
	 * 
	 *  - plain string containing the actual template
	 *  - TypoScript Content Object which can be rendered into a template
	 *  - a package:// reference
	 *
	 *
	 * @param mixed $source
	 */
	public function setSource($source) {
		$this->source = $source;
	}

	/**
	 *
	 * @return <type>
	 */
	public function getSource() {
		return $this->source;
	}

	/**
	 * Assign a value to a variable specified by $key
	 *
	 * @param string $key Name of the variable
	 * @param mixed $value The value to assign
	 * @return void
	 * @author Robert Lemke <robert@typo3.org>
	 */
	public function assign($key, $value) {
		$this->variables[$key] = $value;
	}

	/**
	 * Returns the rendered content of this content object
	 *
	 * @param \F3\TypoScript\RenderingContext $renderingContext
	 * @return string The rendered content as a string
	 * @author Robert Lemke <robert@typo3.org>
	 */
	public function render(\F3\TypoScript\RenderingContext $renderingContext) {
		$this->templateParser->setConfiguration($this->buildParserConfiguration());

		$parsedTemplate = $this->parseTemplate();
		$variableContainer = $parsedTemplate->getVariableContainer();
		return $parsedTemplate->render($this->buildFluidRenderingContext($renderingContext));
	}

	/**
	 * Renders a given section.
	 *
	 * @param string $sectionName Name of section to render
	 * @param \F3\TypoScript\RenderingContext $renderingContext
	 * @return string rendered template for the section
	 * @author Robert Lemke <robert@typo3.org>
	 */
	public function renderSection($sectionName, \F3\TypoScript\RenderingContext $renderingContext) {
		$parsedTemplate = $this->parseTemplate();

		$sections = $parsedTemplate->getVariableContainer()->get('sections');
		if(!array_key_exists($sectionName, $sections)) {
			return FALSE;
		}
		$section = $sections[$sectionName];

		$section->setRenderingContext($this->buildFluidRenderingContext($renderingContext));
		return $section->evaluate();
	}

	/**
	 * Parses and returns the template defined in $this->source
	 *
	 * @return string The parsed template
	 * @author Robert Lemke <robert@typo3.org>
	 */
	protected function parseTemplate() {
		if ($this->source instanceof \F3\TypoScript\ContentObjectInterface) {
			$parsedTemplate = $this->templateParser->parse($this->source->render($renderingContext));
		} elseif (is_string($this->source)) {
			if (substr($this->source, 0, 10) === 'package://') {
				if (file_exists($this->source)) {
					$parsedTemplate = $this->templateParser->parse(file_get_contents($this->source));
				} else {
					$parsedTemplate = 'WARNING: Could not open template source "' . $this->source . '".';
				}
			} else {
				$parsedTemplate = $this->templateParser->parse($this->source);
			}
		} else {
			return 'WARNING: Invalid template source (type: ' . gettype($this->source) . ').';
		}
		return $parsedTemplate;
	}

	/**
	 * Build parser configuration
	 *
	 * @return \F3\Fluid\Core\Parser\Configuration
	 * @author Robert Lemke <robert@typo3.org>
	 */
	protected function buildParserConfiguration() {
		$parserConfiguration = $this->objectManager->create('F3\Fluid\Core\Parser\Configuration');
		$parserConfiguration->addInterceptor($this->objectManager->get('F3\Fluid\Core\Parser\Interceptor\Escape'));
		$parserConfiguration->addInterceptor($this->objectManager->get('F3\Fluid\Core\Parser\Interceptor\Resource'));
		return $parserConfiguration;
	}

	/**
	 * Build the rendering context
	 *
	 * @param \F3\TypoScript\RenderingContext $typoScriptRenderingContext
	 * @param \F3\Fluid\Core\ViewHelper\TemplateVariableContainer $variableContainer
	 * @return \F3\Fluid\Core\Rendering\RenderingContext
	 * @author Sebastian Kurfürst <sebastian@typo3.org>
	 */
	protected function buildFluidRenderingContext(\F3\TypoScript\RenderingContext $typoScriptRenderingContext, \F3\Fluid\Core\ViewHelper\TemplateVariableContainer $variableContainer = NULL) {
		if ($variableContainer === NULL) {
			$variableContainer = $this->objectManager->create('F3\Fluid\Core\ViewHelper\TemplateVariableContainer', $this->variables);
		}

		$renderingContext = $this->objectManager->create('F3\Fluid\Core\Rendering\RenderingContext');
		$renderingContext->setTemplateVariableContainer($variableContainer);
		$renderingContext->setControllerContext($typoScriptRenderingContext->getControllerContext());

		$viewHelperVariableContainer = $this->objectManager->create('F3\Fluid\Core\ViewHelper\ViewHelperVariableContainer');
		$viewHelperVariableContainer->setView($this);
		$renderingContext->setViewHelperVariableContainer($viewHelperVariableContainer);
		return $renderingContext;
	}
}
?>