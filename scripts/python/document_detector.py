#!/usr/bin/env python3
"""
Document detector — finds and crops a document from a photo using OpenCV.

Usage:
    python3 document_detector.py <input.jpg> <output.jpg> [--json /tmp/result.json]

Pipeline:
    1. Grayscale → GaussianBlur → Canny edge detection
    2. findContours → sort by area → approxPolyDP → find quadrilateral
    3. If 4-point contour found → perspective warp (crop + straighten)
    4. Otherwise → copy original (fallback)

Exit codes:
    0 = success (detected or fallback)
    1 = error (file not found, etc.)
"""

import argparse
import json
import sys
from pathlib import Path

import cv2
import numpy as np


def order_points(pts: np.ndarray) -> np.ndarray:
    """Order 4 points as: top-left, top-right, bottom-right, bottom-left."""
    rect = np.zeros((4, 2), dtype="float32")

    s = pts.sum(axis=1)
    rect[0] = pts[np.argmin(s)]  # top-left has smallest sum
    rect[2] = pts[np.argmax(s)]  # bottom-right has largest sum

    d = np.diff(pts, axis=1)
    rect[1] = pts[np.argmin(d)]  # top-right has smallest difference
    rect[3] = pts[np.argmax(d)]  # bottom-left has largest difference

    return rect


def four_point_transform(image: np.ndarray, pts: np.ndarray) -> np.ndarray:
    """Perspective-warp the image to a top-down view of the 4-point region."""
    rect = order_points(pts)
    tl, tr, br, bl = rect

    # Compute destination width
    width_a = np.linalg.norm(br - bl)
    width_b = np.linalg.norm(tr - tl)
    max_width = max(int(width_a), int(width_b))

    # Compute destination height
    height_a = np.linalg.norm(tr - br)
    height_b = np.linalg.norm(tl - bl)
    max_height = max(int(height_a), int(height_b))

    dst = np.array(
        [
            [0, 0],
            [max_width - 1, 0],
            [max_width - 1, max_height - 1],
            [0, max_height - 1],
        ],
        dtype="float32",
    )

    matrix = cv2.getPerspectiveTransform(rect, dst)
    warped = cv2.warpPerspective(image, matrix, (max_width, max_height))

    return warped


def detect_document(image: np.ndarray, min_area_ratio: float = 0.05) -> tuple:
    """
    Detect the largest quadrilateral contour in the image.

    Returns:
        (contour_points, confidence) or (None, 0.0) if not found.
        confidence = contour_area / image_area
    """
    h, w = image.shape[:2]
    image_area = h * w

    gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)
    blurred = cv2.GaussianBlur(gray, (5, 5), 0)
    edged = cv2.Canny(blurred, 30, 200)

    # Dilate edges to close gaps
    kernel = cv2.getStructuringElement(cv2.MORPH_RECT, (3, 3))
    edged = cv2.dilate(edged, kernel, iterations=1)

    contours, _ = cv2.findContours(edged, cv2.RETR_LIST, cv2.CHAIN_APPROX_SIMPLE)

    # Sort by area descending
    contours = sorted(contours, key=cv2.contourArea, reverse=True)

    for contour in contours[:10]:  # Check top 10 largest
        area = cv2.contourArea(contour)

        # Skip if too small
        if area / image_area < min_area_ratio:
            continue

        perimeter = cv2.arcLength(contour, True)
        approx = cv2.approxPolyDP(contour, 0.02 * perimeter, True)

        if len(approx) == 4:
            confidence = area / image_area
            return approx.reshape(4, 2).astype("float32"), confidence

    return None, 0.0


def main():
    parser = argparse.ArgumentParser(description="Detect and crop document from photo")
    parser.add_argument("input", help="Input image path")
    parser.add_argument("output", help="Output image path")
    parser.add_argument("--json", help="Write detection result as JSON to this path")
    args = parser.parse_args()

    input_path = Path(args.input)
    if not input_path.exists():
        print(f"Error: input file not found: {input_path}", file=sys.stderr)
        sys.exit(1)

    image = cv2.imread(str(input_path))
    if image is None:
        print(f"Error: cannot read image: {input_path}", file=sys.stderr)
        sys.exit(1)

    pts, confidence = detect_document(image)

    if pts is not None:
        warped = four_point_transform(image, pts)
        cv2.imwrite(args.output, warped)
        result = {
            "detected": True,
            "width": warped.shape[1],
            "height": warped.shape[0],
            "confidence": round(confidence, 4),
        }
    else:
        # Fallback: copy original
        cv2.imwrite(args.output, image)
        h, w = image.shape[:2]
        result = {
            "detected": False,
            "width": w,
            "height": h,
            "confidence": 0.0,
        }

    if args.json:
        Path(args.json).write_text(json.dumps(result))

    # Print result to stdout for easy capture
    print(json.dumps(result))


if __name__ == "__main__":
    main()
